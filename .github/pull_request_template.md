Please read the [contribution guide](https://github.com/hypervel/components/blob/0.4/src/docs/contributions.md) in full before submitting. Incomplete or ineligible submissions will be closed without a detailed review.

The team handles Laravel and third-party upstream ports. Do not open a porting or synchronization PR. Use the [missing upstream functionality form](https://github.com/hypervel/components/issues/new?template=missing-upstream-functionality.yml) for eligible omissions. Direct bug fixes and performance improvements are welcome even when the issue also exists upstream.

## Contribution type

<!-- Select one. New Hypervel-specific features require explicit maintainer approval in a linked discussion. -->

- [ ] Bug fix
- [ ] Performance improvement
- [ ] Approved Hypervel-specific feature
- [ ] Documentation correction

## Problem and change

<!-- Explain the problem, its cause, and the resulting behavior. For documentation corrections, identify the incorrect or missing guidance. Link the relevant issue or discussion. -->

## Supporting evidence

<!-- Complete the applicable requirement:
Bug fix: identify the regression test and its failing result without the fix and passing result with it.
Performance: provide reproducible before-and-after benchmarks, commands, environment, tradeoffs, and correctness coverage.
New Hypervel-specific feature: link the discussion and the maintainer's explicit approval; describe behavior and relevant failure/isolation tests.
Documentation correction: provide the source or verification supporting the correction.
-->

## Verification

<!-- List the commands run and results. Code PRs require a successful composer fix run from the repository root before opening. For documentation-only PRs, describe how you verified the instructions, examples, and links. -->

## Before submitting

- [ ] I have read the contribution guide in full and this PR meets its requirements.
- [ ] I have reviewed the complete diff, verified its claims, and understand the change.
- [ ] I have included the required tests and documentation for any new or changed behavior.
- [ ] I have completed the required local verification and recorded the results above.

All CI checks must pass before maintainer review. Investigate every automated review finding, fix valid issues, and reply with evidence when a finding is a false positive. Flag disputed findings for a maintainer decision. These review requirements apply after opening the PR; do not claim they have passed in advance.
