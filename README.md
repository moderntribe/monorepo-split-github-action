# GitHub Action for Monorepo Split

**Version 2.0 now supports split to GitHub and Gitlab private repositories!**

<br>

Do you have [a monorepo](https://tomasvotruba.com/cluster/monorepo-from-zero-to-hero/) project on GitHub and need split packages to many repositories? Add this GitHub Action to your workflow and let it split your packages on every commit and tag.

### How does the Split Result Look Like?

This repository splits tests into [symplify/monorepo-split-github-action-test](https://github.com/symplify/monorepo-split-github-action-test) repository.

Not on every commit, but only if contents of `/tests/packages/some-package` directory changes.
Try it yourself - send PR with change in [that directory](/tests/packages/some-package).

<br>

## Docs

- [How to Use on Gitlab](docs/how_to_use_on_gitlab.md)

## Config

Split is basically git push or local directory to remote git repository. This remote repository can be located on GitHub or Gitlab. To be able to do that, it needs a token with write repository access, provided as `PAT` (or `GITHUB_TOKEN`) for GitHub or `GITLAB_TOKEN` for GitLab:

```yaml
env:
    PAT: ${{ secrets.ACCESS_TOKEN }}
    # or
    GITLAB_TOKEN: ${{ secrets.GITLAB_TOKEN }}
```

Set the token in the `env` of the split steps only, not at workflow or job level. Workflow-level `env` exposes the token to every step in every job, including third-party actions and dependency install scripts. Prefer `PAT` over `GITHUB_TOKEN` so it is not confused with the token GitHub provides automatically.

The action authenticates git with an HTTP `Authorization` header passed through `GIT_CONFIG_*` environment variables. The token is never embedded in a clone URL, printed to the log, or written to `.git/config`.

Make sure to add this access token in "Secrets" of package settings at https://github.com/<organization>/<package>/settings/environments

<br>

## Define your GitHub Workflow

```yaml
name: 'Packages Split'

on:
    push:
        branches:
            - main
        tags:
            - '*'

jobs:
    packages_split:
        runs-on: ubuntu-latest

        strategy:
            fail-fast: false
            matrix:
                # define package to repository map
                package:
                    -
                        local_path: 'easy-coding-standard'
                        split_repository: 'easy-coding-standard'

        steps:
            -   uses: actions/checkout@v2

            # no tag
            -
                if: "!startsWith(github.ref, 'refs/tags/')"
                uses: danharrin/monorepo-split-github-action@v2.4.0
                env:
                    # 1. for Github split
                    PAT: ${{ secrets.ACCESS_TOKEN }}

                    # 2. for Gitlab split
                    GITLAB_TOKEN: ${{ secrets.GITLAB_TOKEN }}
                with:
                    # ↓ split "packages/easy-coding-standard" directory
                    package_directory: 'packages/${{ matrix.package.local_path }}'

                    # ↓ into https://github.com/symplify/easy-coding-standard repository
                    repository_organization: 'symplify'
                    repository_name: '${{ matrix.package.split_repository }}'

                    # [optional, with "github.com" as default]
                    repository_host: git.private.com:1234

                    # ↓ the user signed under the split commit
                    user_name: "kaizen-ci"
                    user_email: "info@kaizen-ci.org"

            # with tag
            -
                if: "startsWith(github.ref, 'refs/tags/')"
                uses: danharrin/monorepo-split-github-action@v2.4.0
                env:
                    # 1. for Github split
                    PAT: ${{ secrets.ACCESS_TOKEN }}

                    # 2. for Gitlab split
                    GITLAB_TOKEN: ${{ secrets.GITLAB_TOKEN }}
                with:
                    tag: ${GITHUB_REF#refs/tags/}

                    # ↓ split "packages/easy-coding-standard" directory
                    package_directory: 'packages/${{ matrix.package.local_path }}'

                    # ↓ into https://github.com/symplify/easy-coding-standard repository
                    repository_organization: 'symplify'
                    repository_name: '${{ matrix.package.split_repository }}'

                    # [optional, with "github.com" as default]
                    repository_host: git.private.com:1234

                    # ↓ the user signed under the split commit
                    user_name: "kaizen-ci"
                    user_email: "info@kaizen-ci.org"

            # with custom commit hash
            -
                if: "startsWith(github.ref, 'refs/tags/')"
                uses: "symplify/monorepo-split-github-action@2.1"
                env:
                    # 1. for Github split
                    PAT: ${{ secrets.ACCESS_TOKEN }}

                    # 2. for Gitlab split
                    GITLAB_TOKEN: ${{ secrets.GITLAB_TOKEN }}
                with:
                    tag: ${GITHUB_REF#refs/tags/}
                    commit_hash: ${GITHUB_REF#refs/tags/} # or any other means to get the commit hash

                    # ↓ split "packages/easy-coding-standard" directory
                    package_directory: 'packages/${{ matrix.package.local_path }}'

                    # ↓ into https://github.com/symplify/easy-coding-standard repository
                    repository_organization: 'symplify'
                    repository_name: '${{ matrix.package.split_repository }}'

                    # [optional, with "github.com" as default]
                    repository_host: git.private.com:1234

                    # ↓ the user signed under the split commit
                    user_name: "kaizen-ci"
                    user_email: "info@kaizen-ci.org"
```
