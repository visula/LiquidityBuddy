# Rules

Conventions for changing this codebase. Follow them unless a decision here is changed
in writing. Last updated: 2026-11-02.

---

## 1. Money

1. **One money path.** Every movement of money goes through `PaymentService::recordPayment()`
   (directly, or via `settlePayables()` or `internalTransfer()`). Never insert or update
   `payment`, `transaction` or `duepaid` rows by hand in a feature.
2. **Atomic writes.** Wrap multi-table writes in `runAtomic()` (`MainService`). It rolls back on
   error and is safe when nested.
3. **Lock before funds check.** Withdrawals re-read the account with `getOwnedAccount(..., true)`
   and check available funds inside the transaction (error 0019).
4. **Amounts.** Use `toAmount()` on the server, which stores two decimal places. In the browser,
   amount fields (`#amount`, `input[name="amount"]`) are handled by `default.js`: while typing,
   only digits and one decimal point are kept, with at most two decimals; on focus the separators
   are removed; on leaving the field it shows thousands separators and two decimals (`1,500.50`).
   Read the value with `LB.amountValue`, which strips the separators.
5. **Dates.** The ledger date is `Y-m-d` and cannot be in the future (error 0037). Use
   `validDate()`.
6. **Balances.** `account.currentbalance` is the balance. Editing the balance overwrites it directly,
   as the user chose; that edit creates no transaction row, so it is not in the history. Say so when
   a balance is changed in a way that does not go through the ledger.

## 2. Ownership and safety

7. **Every query is scoped to the logged-in user.** Use `userAccess()` for the user ID and
   filter every table that has `usersessionid`. `transaction` has no owner column: join `account`.
8. **Validate IDs before raw SQL.** `isSessionID()` (32 alphanumeric characters) must pass
   before an ID goes into a `WHERE`, `IN` or raw string.
9. **Parents and links must be the user's own.** Sub-accounts, payment plans and purchase links
   are checked against `usersessionid` (see `parentCandidate`, `linkLabels`).
10. **No secrets in the repo.** `.env` holds database and SMTP settings. Never commit it.
11. **No destructive database changes without approval.** That covers `UPDATE`, `DELETE`,
    `ALTER` and migrations on the live database. Take a `mysqldump --single-transaction` backup
    first and keep it in the scratchpad.

## 3. Errors

12. **Service errors** are arrays from `paymentError(id, category, message)`. The message is
    shown to the user, so write it as a sentence they can act on.
13. **Error ids** are four digits. Use the next free number; never reuse one for a different
    meaning. The ids in use are the `paymentError('….')` and `planError('….')` calls in `app/Controllers`.
14. **HTTP.** JSON endpoints return `apiError` (422), `apiUnauthorised` (401) or `apiResult`.
    Pages redirect to login when not signed in.

## 4. Routing and structure

15. **Explicit routes.** Auto-routing is off. Add every URL to `app/Config/Routes.php`
    and check it with `php spark routes`.
16. **Public methods only** on controllers used as routes.
17. **Put logic in the right layer.** Controllers read requests and return responses.
    Services hold the rules. Table access goes through the table maps (`Traits/DB/`).
18. **Check name clashes** before adding a method to a class with children (see the chain
    in [architecture.md](architecture.md) §2).
19. **Table maps are the contract.** The front end uses keys such as `AccountSessionID`;
    only the map changes when a column changes.

## 5. Database and migrations

20. **Migrations** are SQL files in `DBBackup/Migrations/` named `YYYY_MM_DD_short_name.sql`.
    Each one is idempotent where possible and is run once in the order of its date.
21. **Session IDs** are `char(32)`, filled by `BEFORE INSERT` triggers using `REPLACE(UUID(),'-','')`.
    Do not use `MD5()` in triggers: it is not available on the target MySQL server.
22. **Record the migration** in the change description and in [memories.md](memories.md).

## 6. Front end

23. **Forms post through `LB.post`** with the error element as the target. Show errors with
    `LB.showError` and clear them with `LB.clearError`.
24. **Update the screen from the response.** After a change, refresh the affected region with
    `LB.refreshRegion` or re-render from the JSON. Do not wait for a page reload.
25. **Filters keep the full list.** When a list is filtered (bank, type), rebuild it from a saved
    copy; do not hide options (browsers differ on `hidden` inside `<select>`).
26. **Default selections follow the product.** New account: Cash in hand. Bank accounts show
    the bank details only.
27. **Mobile.** The system must be usable on a phone: single-column rows, touch-sized controls,
    no horizontal scrolling of the page (`mobile.css`). Tables scroll inside their own box.
28. **Third-party scripts** are loaded from a CDN with an exact version (for example
    `chart.js@4.4.1`). Do not use floating versions.

## 7. Files and line endings

29. **Working copy uses CRLF** for many files (git `autocrlf`). When a multi-line edit fails on a
    CRLF file, convert it to LF, edit, and convert it back to CRLF. Check with `file`.
30. **Scratch files** (probes, scripts, backups, test pages) go in the scratchpad directory,
    not the repo. Temporary Spark commands in `app/Commands/` are deleted after use.
31. **Do not commit `writable/`, `.env`, `vendor/` or `node_modules/`.**

## 8. Verification

32. **Verify before reporting done.** At minimum: `php -l` on changed PHP files,
    `node --check` on changed JS, `php spark routes` after route changes, and a render check of
    changed pages.
33. **Logic checks run inside a transaction** that is rolled back (`transBegin()` /
    `transRollback()`). Confirm afterwards that the row counts are unchanged.
34. **Say what was not verified.** Visual checks in a real browser are not done by the test
    commands; report them as not done.

## 9. Git

35. **Commit and push only when asked.** Use a branch for new work; the default branch is `main`.
    Recent commit messages vary in style; match the message style of the branch.

## 10. Documentation

37. **Behaviour changes update the docs.** Specs are in `docs/`. A new feature gets a short
    section in the matching document, and a line in [memories.md](memories.md).
38. **Decisions are recorded** in [memories.md](memories.md) with the reason. Open questions
    go in [tasks.md](tasks.md).
