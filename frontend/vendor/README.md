# SHLZ UI vendor packages

All local packages in this directory are generated from authoritative SHLZ UI
commit `b8ea1084cdfd7c85f241bb8174a5f05e29d9f879` (`Merge pull request #3 from
Antropophag/feat/document-row-final`).

The reproducible build uses the repository's existing package workflow:

```sh
npm ci --no-audit --no-fund
npm run generate
npm run build:packages
npm pack --workspace @shlz/tokens --workspace @shlz/icons \
  --workspace @shlz/styles --workspace @shlz/behaviors
```

Build and pack all four packages from the same clean source revision. Never
replace an individual tarball or reuse package artifacts from an older IC
integration branch.
