# Rules of work

These instructions are loaded automatically every session. Keep them short and specific.

## What this repo is

- The product is **one WordPress plugin**: `simyatech-tagmanager-suite/`. That plus
  `audit/` and `.gitignore` is the entire tracked contents of this repo.
- Every other top-level folder (`bookly-addon-*`, `bookly-maintenance`,
  `bookly-meetings-upload`, `bookly-responsive-appointment-booking-tool`) is a
  **vendor copy of a third-party Bookly plugin**, kept on disk for reference only.
  They are **deliberately untracked**. They are not ours, they are not versioned here,
  and they must never enter a commit.

## Staging — never use `git add -A` or `git add .`

- **Always stage explicit paths**: `git add simyatech-tagmanager-suite/assets/js/datalayer.js`.
  Never `git add -A`, never `git add .`, never `git add :/`, never `git commit -a`.
- **Why:** thousands of untracked vendor Bookly files sit next to the plugin. A single
  `git add -A` at the repo root swept **2,818** of them into a commit and pushed it to
  `main` (2026-09-27). Recovering that needed a history rewrite and a force-push.
- **Before every commit**, run `git status --porcelain` and check the staged list is
  *only* the files you meant to touch. If the count surprises you, unstage and start over.
- **After committing**, `git show --stat HEAD` and confirm the file count matches.

## Editing files

- All work goes in `simyatech-tagmanager-suite/`. **Never edit a vendor `bookly-*` file** —
  it is third-party code that gets overwritten on plugin update. If a change seems to need
  one, solve it in our plugin (hooks, filters, our own JS) instead, or stop and say so.
- Files in this repo use **CRLF** line endings. Preserve them — a whole-file rewrite with
  LF turns a two-line change into a two-thousand-line diff.
- On `main`, confirm before editing. On a task branch, edit freely.

## Commits and pushes

- Conventional-commit format for messages.
- Never commit or push on `main` without asking first.
- **Never force-push without explicit permission**, and when it is granted use
  `--force-with-lease`, never bare `--force`.

## Data layer work

- GA4 parameter names belong in the dataLayer push under **GA4's own names**
  (`transaction_id`, `value`, `currency`), not only ours (`order_id`, `order_total`), so a
  GTM tag can map them without a rename.
- A dataLayer key does nothing on its own — it is inert until a GTM tag sends it. When a
  task is "add parameter X", say plainly which half is code here and which half is a GTM
  container change that has to be done in the GTM UI.
- GA4's automatic `transaction_id` deduplication applies to the **`purchase`** event. On a
  custom event name it is just another parameter and buys no dedupe.

## Updating markdown docs

- When work resolves something listed in a doc under `audit/`, mark it by **adding a ✅ on
  that existing line**. Never delete the finding, never replace the section with a summary.
  Keep the original text; if the wording became inaccurate, strike it through and append the
  correction, but the original stays visible.
- When only part of an item is done (e.g. the code half of a GTM task), say so on the line
  instead of marking it ✅.
