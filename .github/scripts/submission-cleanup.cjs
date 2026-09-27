const DAY = 24 * 60 * 60 * 1000;
const GUIDE = 'https://github.com/hypervel/components/blob/0.4/src/docs/contributions.md';
const EXEMPT_LABEL = 'maintainer-directed';
const INFO_LABEL = 'not enough info';
const ACTIVITY_MARKER = /<!-- hypervel-draft-activity (\{[^\n]+\}) -->/;

module.exports = async function cleanup({ github, context, core, now = new Date(), dryRun = false }) {
    const repository = context.repo;
    const permissions = new Map();

    async function permissionFor(login) {
        if (!permissions.has(login)) {
            const { data } = await github.rest.repos.getCollaboratorPermissionLevel({
                ...repository, username: login,
            });
            permissions.set(login, data);
        }

        return permissions.get(login);
    }

    async function isMaintainer(user, allowTriage = false) {
        if (!user || user.type === 'Bot') return false;
        const permission = await permissionFor(user.login);

        return ['admin', 'write', 'maintain'].includes(permission.permission)
            || (allowTriage && permission.role_name === 'triage');
    }

    function isExempt(item) {
        return item.labels.some(label => label.name === EXEMPT_LABEL);
    }

    async function commentsFor(number) {
        return github.paginate(github.rest.issues.listComments, {
            ...repository, issue_number: number, per_page: 100,
        });
    }

    async function authorEditAndDraftStart(item) {
        const { node } = await github.graphql(`
            query($id: ID!) {
                node(id: $id) {
                    ... on Issue { lastEditedAt editor { login } }
                    ... on PullRequest {
                        lastEditedAt
                        editor { login }
                        timelineItems(last: 1, itemTypes: [CONVERT_TO_DRAFT_EVENT, REOPENED_EVENT]) {
                            nodes {
                                ... on ConvertToDraftEvent { createdAt }
                                ... on ReopenedEvent { createdAt }
                            }
                        }
                    }
                }
            }
        `, { id: item.node_id });

        return {
            editedAt: node.editor?.login === item.user.login ? node.lastEditedAt : null,
            draftStartedAt: node.timelineItems?.nodes[0]?.createdAt,
        };
    }

    async function recordDraftActivity(pullRequest, comments) {
        const previous = comments.find(comment =>
            comment.user?.login === 'github-actions[bot]' && ACTIVITY_MARKER.test(comment.body));
        const activity = { head: pullRequest.head.sha, at: now.toISOString() };
        const body = `External draft pull requests are closed after seven days without contributor activity. `
            + `Pushes, edits, and author replies count; bot comments do not. `
            + `Maintainers can exempt directed work with the \`${EXEMPT_LABEL}\` label. `
            + `See the [contribution guide](${GUIDE}#pull-request-requirements).\n\n`
            + `<!-- hypervel-draft-activity ${JSON.stringify(activity)} -->`;

        core.info(`Record draft activity for #${pullRequest.number}${dryRun ? ' (dry run)' : ''}`);
        if (dryRun) return;
        if (previous) {
            await github.rest.issues.updateComment({ ...repository, comment_id: previous.id, body });
        } else {
            await github.rest.issues.createComment({ ...repository, issue_number: pullRequest.number, body });
        }
    }

    async function checkDraft(pullRequest, recordActivity = false) {
        if (pullRequest.state !== 'open' || !pullRequest.draft || isExempt(pullRequest)
            || !pullRequest.user || pullRequest.user.type === 'Bot'
            || await isMaintainer(pullRequest.user)) return;

        const comments = await commentsFor(pullRequest.number);
        const notice = comments.find(comment =>
            comment.user?.login === 'github-actions[bot]' && ACTIVITY_MARKER.test(comment.body));
        const recorded = notice ? JSON.parse(notice.body.match(ACTIVITY_MARKER)[1]) : null;

        // Commit dates do not record push times. Remember observed head changes,
        // including pushes missed by event delivery, and give existing drafts a full grace period.
        if (recordActivity || !recorded || recorded.head !== pullRequest.head.sha) {
            await recordDraftActivity(pullRequest, comments);
            return;
        }

        const edits = await authorEditAndDraftStart(pullRequest);
        let lastActivity = Math.max(Date.parse(recorded.at), Date.parse(pullRequest.created_at),
            edits.editedAt ? Date.parse(edits.editedAt) : 0,
            edits.draftStartedAt ? Date.parse(edits.draftStartedAt) : 0);
        if (now.getTime() - lastActivity < 7 * DAY) return;

        const reviews = await github.paginate(github.rest.pulls.listReviews, {
            ...repository, pull_number: pullRequest.number, per_page: 100,
        });
        const reviewComments = await github.paginate(github.rest.pulls.listReviewComments, {
            ...repository, pull_number: pullRequest.number, per_page: 100,
        });

        for (const response of [...comments, ...reviews, ...reviewComments]) {
            if (response.user?.login !== pullRequest.user.login || response.user.type === 'Bot') continue;
            for (const date of [response.created_at, response.updated_at, response.submitted_at]) {
                if (date) lastActivity = Math.max(lastActivity, Date.parse(date));
            }
        }
        if (now.getTime() - lastActivity < 7 * DAY) return;

        const { data: current } = await github.rest.pulls.get({
            ...repository, pull_number: pullRequest.number,
        });
        if (current.state !== 'open' || !current.draft || isExempt(current)
            || current.head.sha !== pullRequest.head.sha || current.updated_at !== pullRequest.updated_at) return;

        core.info(`Close inactive external draft #${current.number}${dryRun ? ' (dry run)' : ''}`);
        if (dryRun) return;
        await github.rest.issues.createComment({
            ...repository, issue_number: current.number,
            body: `Closing this external draft after seven days without contributor activity. `
                + `Bot comments do not extend the deadline. You can reopen it when you resume the work. `
                + `See the [contribution guide](${GUIDE}#pull-request-requirements).`,
        });
        await github.rest.pulls.update({ ...repository, pull_number: current.number, state: 'closed' });
    }

    async function checkInformationRequest(issue) {
        if (issue.pull_request || isExempt(issue) || !issue.user || issue.user.type === 'Bot') return;
        const events = await github.paginate(github.rest.issues.listEventsForTimeline, {
            ...repository, issue_number: issue.number, per_page: 100,
        });
        const request = events.filter(event => event.event === 'labeled' && event.label.name === INFO_LABEL)
            .sort((left, right) => Date.parse(right.created_at) - Date.parse(left.created_at))[0];
        if (!request || now.getTime() - Date.parse(request.created_at) < 14 * DAY
            || !await isMaintainer(request.actor, true)) return;

        const requestedAt = Date.parse(request.created_at);
        const comments = await commentsFor(issue.number);
        const authorResponded = comments.some(comment => comment.user?.login === issue.user.login
            && comment.user.type !== 'Bot'
            && Math.max(Date.parse(comment.created_at), Date.parse(comment.updated_at)) >= requestedAt);
        const authorReopened = events.some(event => event.event === 'reopened'
            && event.actor?.login === issue.user.login && Date.parse(event.created_at) >= requestedAt);
        const edits = await authorEditAndDraftStart(issue);
        if (authorResponded || authorReopened || (edits.editedAt && Date.parse(edits.editedAt) >= requestedAt)) return;

        const { data: current } = await github.rest.issues.get({ ...repository, issue_number: issue.number });
        if (current.state !== 'open' || isExempt(current)
            || !current.labels.some(label => label.name === INFO_LABEL) || current.updated_at !== issue.updated_at) return;

        core.info(`Close unanswered information request #${current.number}${dryRun ? ' (dry run)' : ''}`);
        if (dryRun) return;
        await github.rest.issues.createComment({
            ...repository, issue_number: current.number,
            body: `Closing this issue because the reporter has not responded within 14 days of the maintainer's `
                + `request for information. This does not mean the reported problem is fixed. `
                + `Supply the requested information here so the issue can be reconsidered. `
                + `See the [contribution guide](${GUIDE}#bug-reports).`,
        });
        await github.rest.issues.update({
            ...repository, issue_number: current.number, state: 'closed', state_reason: 'not_planned',
        });
    }

    if (context.eventName === 'pull_request_target') {
        if (context.payload.sender?.type === 'Bot') return;
        const { data: pullRequest } = await github.rest.pulls.get({
            ...repository, pull_number: context.payload.pull_request.number,
        });
        await checkDraft(pullRequest, true);
        return;
    }

    for await (const { data: pullRequests } of github.paginate.iterator(github.rest.pulls.list, {
        ...repository, state: 'open', per_page: 100,
    })) {
        for (const pullRequest of pullRequests) await checkDraft(pullRequest);
    }

    for await (const { data: issues } of github.paginate.iterator(github.rest.issues.listForRepo, {
        ...repository, state: 'open', labels: INFO_LABEL, per_page: 100,
    })) {
        for (const issue of issues) await checkInformationRequest(issue);
    }
};
