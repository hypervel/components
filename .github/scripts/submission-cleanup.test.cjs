const assert = require('node:assert/strict');
const { test } = require('node:test');
const cleanup = require('./submission-cleanup.cjs');

const now = new Date('2026-10-20T12:00:00Z');
const author = { login: 'contributor', type: 'User' };
const maintainer = { login: 'maintainer', type: 'User' };
const bot = { login: 'github-actions[bot]', type: 'Bot' };
const oldDate = '2026-10-01T12:00:00Z';
const recentDate = '2026-10-19T12:00:00Z';

function draft(overrides = {}) {
    return {
        number: 1, node_id: 'PR_1', state: 'open', draft: true, user: author,
        labels: [], head: { sha: 'old-head' }, created_at: oldDate, updated_at: oldDate,
        ...overrides,
    };
}

function issue(overrides = {}) {
    return {
        number: 2, node_id: 'I_2', state: 'open', user: author,
        labels: [{ name: 'not enough info' }], created_at: oldDate, updated_at: oldDate,
        ...overrides,
    };
}

function notice(at = oldDate, head = 'old-head') {
    return {
        id: 10, user: bot, created_at: oldDate, updated_at: oldDate,
        body: `<!-- hypervel-draft-activity ${JSON.stringify({ head, at })} -->`,
    };
}

function request(createdAt = oldDate, actor = maintainer) {
    return { event: 'labeled', label: { name: 'not enough info' }, actor, created_at: createdAt };
}

function response(user = author, createdAt = recentDate) {
    return { user, created_at: createdAt, updated_at: createdAt, body: 'More details' };
}

async function run(options = {}) {
    const mutations = [];
    const logs = [];
    const calls = [];
    const pullRequests = options.pullRequests || [];
    const issues = options.issues || [];
    const methods = [
        'repos.getCollaboratorPermissionLevel', 'pulls.list', 'pulls.get', 'pulls.listReviews',
        'pulls.listReviewComments', 'pulls.update', 'issues.listForRepo', 'issues.get',
        'issues.listComments', 'issues.listEventsForTimeline', 'issues.createComment',
        'issues.updateComment', 'issues.update',
    ];
    const rest = { repos: {}, pulls: {}, issues: {} };
    for (const method of methods) {
        const [resource, name] = method.split('.');
        rest[resource][name] = async parameters => {
            calls.push({ method, ...parameters });
            if (method === 'repos.getCollaboratorPermissionLevel') {
                return { data: options.permissions?.[parameters.username] || {
                    permission: parameters.username === 'maintainer' ? 'write' : 'read',
                } };
            }
            if (method === 'pulls.get') {
                const current = pullRequests.find(item => item.number === parameters.pull_number);
                return { data: { ...current, ...options.currentPullRequest } };
            }
            if (method === 'issues.get') {
                const current = issues.find(item => item.number === parameters.issue_number);
                return { data: { ...current, ...options.currentIssue } };
            }
            mutations.push({ method, ...parameters });
            return { data: {} };
        };
    }
    const paginate = async (method, parameters) => {
        if (method === rest.issues.listComments) return options.comments?.[parameters.issue_number] || [];
        if (method === rest.issues.listEventsForTimeline) return options.events || [];
        if (method === rest.pulls.listReviews) return options.reviews || [];
        if (method === rest.pulls.listReviewComments) return options.reviewComments || [];
        throw new Error('Unexpected paginated endpoint');
    };
    paginate.iterator = async function* (method, parameters) {
        if (method === rest.pulls.list) {
            // Separate pages ensure a sweep processes more than its first page.
            for (const pullRequest of pullRequests) yield { data: [pullRequest] };
        } else {
            assert.equal(parameters.labels, 'not enough info');
            for (const item of issues) yield { data: [item] };
        }
    };
    const github = {
        rest, paginate,
        graphql: async (query, variables) => ({
            node: options.edits?.[variables.id] || { lastEditedAt: null, editor: null },
        }),
    };
    await cleanup({
        github,
        context: {
            repo: { owner: 'hypervel', repo: 'components' },
            eventName: options.eventName || 'schedule', payload: options.payload || {},
        },
        core: { info: message => logs.push(message) }, now, dryRun: options.dryRun || false,
    });
    return { mutations, logs, calls };
}

test('closes an inactive external draft with an explanation and no branch deletion', async () => {
    const { mutations } = await run({ pullRequests: [draft()], comments: { 1: [notice()] } });
    assert.deepEqual(mutations.map(change => change.method), ['issues.createComment', 'pulls.update']);
    assert.match(mutations[0].body, /seven days/);
    assert.match(mutations[0].body, /contributions\.md/);
    assert.equal(mutations[1].state, 'closed');
});

test('gives existing drafts a full seven days from their first notice', async () => {
    const { mutations } = await run({ pullRequests: [draft()] });
    assert.equal(mutations.length, 1);
    assert.equal(mutations[0].method, 'issues.createComment');
    assert.match(mutations[0].body, /2026-10-20T12:00:00.000Z/);
});

test('records a newly observed head even if the commit has an old date', async () => {
    const { mutations } = await run({
        pullRequests: [draft({ head: { sha: 'new-head' } })], comments: { 1: [notice()] },
    });
    assert.equal(mutations.length, 1);
    assert.equal(mutations[0].method, 'issues.updateComment');
    assert.match(mutations[0].body, /new-head/);
});

test('ignores contributor-supplied tracking markers', async () => {
    const forged = { ...notice(), user: author };
    const { mutations } = await run({ pullRequests: [draft()], comments: { 1: [forged] } });
    assert.equal(mutations[0].method, 'issues.createComment');
    assert.equal(mutations.length, 1);
});

test('honors the seven-day boundary', async () => {
    const before = await run({
        pullRequests: [draft()], comments: { 1: [notice('2026-10-13T12:00:01Z')] },
    });
    assert.equal(before.mutations.length, 0);
    const atDeadline = await run({
        pullRequests: [draft()], comments: { 1: [notice('2026-10-13T12:00:00Z')] },
    });
    assert.equal(atDeadline.mutations.at(-1).method, 'pulls.update');
});

test('exempts ready, closed, maintainer-authored, bot-authored, and labeled pull requests', async () => {
    for (const overrides of [
        { draft: false }, { state: 'closed' }, { user: maintainer }, { user: bot },
        { labels: [{ name: 'maintainer-directed' }] },
    ]) {
        const { mutations } = await run({ pullRequests: [draft(overrides)] });
        assert.equal(mutations.length, 0);
    }
});

test('author comments, comment edits, reviews, and review replies extend the deadline', async () => {
    for (const additions of [
        { comments: { 1: [notice(), response()] } },
        { comments: { 1: [notice(), { ...response(author, oldDate), updated_at: recentDate }] } },
        { reviews: [{ user: author, submitted_at: recentDate }] },
        { reviewComments: [response()] },
    ]) {
        const { mutations } = await run({
            pullRequests: [draft()], comments: { 1: [notice()] }, ...additions,
        });
        assert.equal(mutations.length, 0);
    }
});

test('bot comments and maintainer reviews do not reset the draft timer', async () => {
    const { mutations } = await run({
        pullRequests: [draft()], comments: { 1: [notice(), response(bot)] },
        reviews: [{ user: maintainer, submitted_at: recentDate }],
        reviewComments: [response(bot)],
    });
    assert.equal(mutations.at(-1).method, 'pulls.update');
});

test('author body edits and recent draft conversion or reopening extend the deadline', async () => {
    for (const edit of [
        { lastEditedAt: recentDate, editor: { login: author.login } },
        { timelineItems: { nodes: [{ createdAt: recentDate }] } },
    ]) {
        const { mutations } = await run({
            pullRequests: [draft()], comments: { 1: [notice()] }, edits: { PR_1: edit },
        });
        assert.equal(mutations.length, 0);
    }
});

test('does not close a draft changed while its activity was being checked', async () => {
    for (const currentPullRequest of [
        { draft: false }, { state: 'closed' }, { head: { sha: 'new-head' } },
        { updated_at: recentDate }, { labels: [{ name: 'maintainer-directed' }] },
    ]) {
        const { mutations } = await run({
            pullRequests: [draft()], comments: { 1: [notice()] }, currentPullRequest,
        });
        assert.equal(mutations.length, 0);
    }
});

test('human pull request events record activity without closing anything', async () => {
    const { mutations } = await run({
        pullRequests: [draft()], comments: { 1: [notice()] }, eventName: 'pull_request_target',
        payload: { pull_request: { number: 1 }, sender: author },
    });
    assert.equal(mutations.length, 1);
    assert.equal(mutations[0].method, 'issues.updateComment');
});

test('bot-triggered edits do not update the draft activity notice', async () => {
    const { mutations } = await run({
        pullRequests: [draft()], eventName: 'pull_request_target',
        payload: { pull_request: { number: 1 }, sender: bot },
    });
    assert.equal(mutations.length, 0);
});

test('closes an unanswered maintainer information request as not planned, not fixed', async () => {
    const { mutations } = await run({ issues: [issue()], events: [request()] });
    assert.deepEqual(mutations.map(change => change.method), ['issues.createComment', 'issues.update']);
    assert.match(mutations[0].body, /does not mean the reported problem is fixed/);
    assert.equal(mutations[1].state_reason, 'not_planned');
});

test('requires a maintainer information request and honors its fourteen-day boundary', async () => {
    for (const events of [[], [request(recentDate)], [request(oldDate, author)], [request(oldDate, bot)]]) {
        assert.equal((await run({ issues: [issue()], events })).mutations.length, 0);
    }
    const result = await run({ issues: [issue()], events: [request('2026-10-06T12:00:00Z')] });
    assert.equal(result.mutations.at(-1).method, 'issues.update');
});

test('accepts information requests from repository triagers', async () => {
    const { mutations } = await run({
        issues: [issue()], events: [request(oldDate, { login: 'triager', type: 'User' })],
        permissions: { triager: { permission: 'read', role_name: 'triage' } },
    });
    assert.equal(mutations.at(-1).method, 'issues.update');
});

test('a reporter response stops closure even if the maintainer has not replied for fourteen days', async () => {
    const { mutations } = await run({
        issues: [issue()], events: [request()], comments: { 2: [response(author, '2026-10-02T12:00:00Z')] },
    });
    assert.equal(mutations.length, 0);
});

test('reporter body edits, comment edits, and reopenings count as responses', async () => {
    for (const additions of [
        { edits: { I_2: { editor: { login: author.login }, lastEditedAt: recentDate } } },
        { comments: { 2: [{ ...response(author, '2026-09-01T12:00:00Z'), updated_at: recentDate }] } },
        { events: [request(), { event: 'reopened', actor: author, created_at: recentDate }] },
    ]) {
        assert.equal((await run({ issues: [issue()], events: [request()], ...additions })).mutations.length, 0);
    }
});

test('bot and other-user replies are not reporter responses', async () => {
    const { mutations } = await run({
        issues: [issue()], events: [request()], comments: { 2: [response(bot), response(maintainer)] },
        edits: { I_2: { editor: { login: bot.login }, lastEditedAt: recentDate } },
    });
    assert.equal(mutations.at(-1).method, 'issues.update');
});

test('reapplying the information label starts a new request', async () => {
    const { mutations } = await run({
        issues: [issue()], events: [request(), request(recentDate)],
    });
    assert.equal(mutations.length, 0);
});

test('exempts directed issues and never treats a pull request as an information-request issue', async () => {
    for (const overrides of [
        { labels: [{ name: 'not enough info' }, { name: 'maintainer-directed' }] },
        { pull_request: {} }, { user: bot },
    ]) {
        assert.equal((await run({ issues: [issue(overrides)], events: [request()] })).mutations.length, 0);
    }
});

test('rechecks issue state, exemption, label, and activity before closing', async () => {
    for (const currentIssue of [
        { state: 'closed' }, { labels: [] }, { updated_at: recentDate },
        { labels: [{ name: 'not enough info' }, { name: 'maintainer-directed' }] },
    ]) {
        assert.equal((await run({ issues: [issue()], events: [request()], currentIssue })).mutations.length, 0);
    }
});

test('processes subsequent API pages', async () => {
    const { mutations } = await run({
        pullRequests: [draft({ draft: false }), draft({ number: 3 })], comments: { 3: [notice()] },
    });
    assert.equal(mutations.at(-1).pull_number, 3);
});

test('dry runs make no writes for either closures or initial notices', async () => {
    const { mutations, logs } = await run({
        pullRequests: [draft(), draft({ number: 3 })], comments: { 1: [notice()] },
        issues: [issue()], events: [request()], dryRun: true,
    });
    assert.equal(mutations.length, 0);
    assert.equal(logs.length, 3);
    assert.ok(logs.every(message => message.endsWith('(dry run)')));
});
