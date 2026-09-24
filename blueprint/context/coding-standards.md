# Coding Standards

> Your conventions. Edit these once to match your stack.
>
> Run `/onboard` after installing the Blueprint. It tunes this file to the real
> project stack, along with `AGENTS.md`, `CLAUDE.md` when present,
> `ai-interaction.md`, `.gitignore`, and README placement. Review the result
> before `/overview`.

This is a two-app repo: `backend/` is a Laravel API, `frontend/` is a React +
TypeScript SPA. Standards below are split accordingly.

## Backend: PHP / Laravel

- PHP 8.3+, Laravel 13 (framework skeleton defaults)
- Business logic belongs in Action/Service classes, not controllers, once the
  domain grows past trivial CRUD (see `roastingservice-build-plan.md`)
- Use Form Requests for validation; use API Resources for JSON responses once
  API endpoints exist
- Eloquent for database access; migrations are the only way schema changes -
  never edit the database by hand
- Money and other precise decimals: use `decimal`/`numeric` columns, never
  `float`
- Code style: `vendor/bin/pint`

> TODO: no API routes, Sanctum auth, or non-default database driver exist yet -
> the backend is still the stock `laravel/laravel` skeleton on SQLite. The
> project plan calls for Sanctum + PostgreSQL; that lands as its own build-plan
> feature, not an onboarding change.

## Frontend: TypeScript / React

- Strict mode enabled
- No `any` types - use proper typing or `unknown`
- Define interfaces for all props, API responses, and data models
- Use type inference where obvious, explicit types where helpful
- Functional components only (no class components)
- Use hooks for state and side effects
- Keep components focused - one job per component
- Extract reusable logic into custom hooks

> TODO: no router or data-fetching library is installed yet (bare scaffold).
> The project plan's `features/<module>` structure (TanStack Query, React
> Router, React Hook Form + Zod) is a future build-plan feature, not yet the
> real layout.

## File Organization

- Frontend components: `frontend/src/components/[feature]/ComponentName.tsx`
  (shadcn primitives live in `frontend/src/components/ui/`)
- Frontend lib/utils: `frontend/src/lib/[utility].ts`
- Backend controllers: `backend/app/Http/Controllers/`
- Backend models: `backend/app/Models/`
- Backend migrations: `backend/database/migrations/`

## Naming

- Components: PascalCase (`ItemCard.tsx`)
- Files: Match component name or kebab-case
- Functions/variables: camelCase (TS), camelCase (PHP methods/variables)
- Constants: SCREAMING_SNAKE_CASE
- Types/Interfaces: PascalCase (no prefix)
- PHP classes: PascalCase; Laravel conventions for controllers (`XController`),
  models (singular), migrations (snake_case, timestamped)

## Styling

- Tailwind CSS v4 for all styling, CSS-first config (`@theme` block in
  `frontend/src/index.css`), no `tailwind.config.js`
- Use shadcn/ui components where applicable (`components.json` in `frontend/`)
- No inline styles
- Light mode first (per project plan), dark mode via the `.dark` class shadcn
  already wires up

## Database

- Eloquent migrations are the only way to change schema:
  `php artisan make:migration` then `php artisan migrate`
- Run `php artisan migrate:status` before committing to verify migrations are
  in sync
- Seeders and factories belong under `backend/database/seeders` and
  `backend/database/factories`

> TODO: still SQLite (Laravel skeleton default). Confirm/switch to PostgreSQL
> when the database driver is actually configured.

## Data Fetching

- Backend: Eloquent in controllers/services, one JSON API under a versioned
  prefix once routes exist (see `roastingservice-build-plan.md`, `/api/v1`)
- Backend: validate all inputs with Form Requests
- Frontend: no HTTP client is installed yet - add one deliberately (the plan
  calls for axios/TanStack Query) as its own build-plan step, not a silent
  mid-feature install

## Error Handling

- Backend: let Laravel's exception handler produce JSON error responses for
  API routes; use Form Request validation instead of manual `try/catch` where
  possible
- Frontend: surface errors to the user (toast or inline message) rather than
  failing silently; this becomes concrete once a data-fetching library exists

## Testing

The backend already ships a working test runner: PHPUnit via `php artisan test`
(the Laravel skeleton default, confirmed passing). That command is declared in
`AGENTS.md`, so per the opt-in rule below, tests are already a gate for
logic-bearing backend steps.

The frontend has no test runner yet; that's opt-in at the project level until
someone adds one. Adding unit testing is an explicit setup task the AI can do
through the normal workflow, either as a build-plan item or with `/tests`. The
setup should choose the stack-native runner (Vitest), wire the scripts or
commands, add a small example test, and update the Commands section of
`AGENTS.md`.

When `AGENTS.md` declares a `Verify` command, treat it as the umbrella automated
gate. It combines only the checks this project actually has, in this order when
available: typecheck, tests, then build. The command does not enable an absent
test runner or replace focused evidence. It gives local work and optional CI one
exact command to run. `/ci` owns Verify and CI setup. `/tests` adds the real test
command to Verify when it already exists, but never creates CI only because
testing was configured.

**The opt-in switch is one signal: a `test` command in the Commands section of
`AGENTS.md`.** Declare one and **tests become a gate for logic-bearing steps**,
not an optional extra; leave it out and the loop verifies logic with the evidence
it already uses (run it, a screenshot, the build). Adding the runner is itself a
deliberate step, never a silent mid-step install. This is the single definition
of the switch; the skills and `ai-interaction.md` only point back here.

- **What to test (the scope rule):** pure logic where a wrong answer is possible -
  parsers, formatters, validators, id/slug builders, server actions. These have
  assertable inputs and outputs and real edge cases (empty, missing, malformed).
- **What not to test:** UI components and integration-level surfaces (render or
  export routes, anything driving a real browser or external service). Verify those
  with a screenshot and the build, not brittle unit tests.
- **The gate (when a runner is configured):** a build step that adds in-scope logic
  must ship a passing test in the same reviewable diff. The project's test command
  must be green before the step is approved, before any checkpoint commit, and
  before `/complete` merges. UI and integration-only steps are exempt and ride on
  screenshot plus build evidence.
- **When it's named:** the `/feature` spec's Testing section predicts the coverage,
  `/implement` writes the test with the step, and if a step surfaces logic the spec
  didn't foresee, add a focused test then.
- An empty suite should fail, not pass, so "no tests ran" never looks like "passed".
- Backend test files live under `backend/tests/Feature/` or
  `backend/tests/Unit/`, mirroring Laravel convention. Frontend test files, once
  a runner exists, live next to source files (for example `feature.test.ts`).
- Run them via the project's test command (see Commands in `AGENTS.md`), not a
  hardcoded tool name.

Stack binding: the backend uses PHPUnit (`php artisan test`) with Laravel's
`RefreshDatabase`/factories for setup; the frontend will use Vitest once
adopted, with `vi.mock()` for external calls and `vi.useFakeTimers()` for
time-dependent logic.

## Browser Verification

For UI and integration behavior, prefer real browser evidence over reading the
code and assuming it works.

- Browser automation is separately opt-in through `/tests browser`. That setup
  reuses a compatible runner or prefers Playwright for supported projects, then
  documents the exact command as `Browser tests` in `AGENTS.md`.
- When `Browser tests` is declared, add focused coverage for stable behavioral
  done-whens when it is proportionate, and run the documented command during
  `/check`. Do not assume it proves visual fidelity, real authenticated-profile
  behavior, browser chrome, or another claim the test does not observe.
- If no Browser tests command is declared, do not add a runner silently in the
  middle of an unrelated feature. Use the available dev server, browser
  screenshots, build output, API output, or manual evidence instead.
- Browser tests are not part of the default Verify command or CI unless the user
  separately chooses that slower gate.
- Browser evidence is especially important for flows that click, type, submit,
  navigate, download files, render complex layouts, or depend on client-side
  state.

## Code Quality

- No commented-out code unless specified
- No unused imports or variables
- Keep functions under 50 lines when possible

## Comments

Write code that explains itself; comment only what the code cannot say.
Over-commenting is a common AI tell, so resist it.

- Comment the **why**, not the **what**. Delete any comment that restates the code.
- No banner/header blocks, section dividers, or step-by-step narration of obvious
  code. A file does not need a comment announcing each region.
- A comment earns its place only when it captures something the code can't: a
  non-obvious decision, a gotcha or workaround, why a value is what it is, or a
  link to a spec or issue.
- Prefer self-documenting names and small functions over explanatory comments.
- Keep doc comments minimal: a one-line purpose on an exported type or function is
  plenty; don't write JSDoc that just repeats the signature.
- When in doubt, leave the comment out.

## Writing

- No em dashes (U+2014) in generated content: docs, comments, commit messages,
  READMEs, specs. They read as AI-generated.
- Use a hyphen for `term - description` separators; rephrase prose with commas,
  parentheses, or a colon. Avoid en dashes and the ellipsis character too.
