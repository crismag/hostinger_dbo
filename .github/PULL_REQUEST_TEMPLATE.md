### Summary
What this change does and why.

### Security impact
Confirm the invariants still hold (or N/A):

- [ ] Identifiers remain registry-allowlisted (no caller-supplied identifiers reach SQL)
- [ ] All values are parameter-bound (no string concatenation of request values)
- [ ] No raw SQL from callers; no handler classes resolved from config/DB
- [ ] Least-privilege (client permissions / tenant scope) preserved

### Verification
How you tested it (commands, drivers exercised):

- [ ] `php -l` clean
- [ ] Smoke suite passes (SQLite)
- [ ] Tested on MySQL (if applicable)

### Docs / changelog
- [ ] `CHANGELOG.md` updated under `[Unreleased]`
- [ ] Affected docs updated
