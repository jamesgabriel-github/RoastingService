# Fix: Move inventory log into a modal

**Type:** Fix
**Status:** verified
**Branch:** fix/inventory-log-modal

## The problem

In the Inventory tab (`frontend/src/features/inventory/InventoryPage.tsx`), the
inventory log (`InventoryLogTable`) is rendered inline at the bottom of the page,
under the services table. It's always visible and takes up permanent page space
even when nobody needs it.

## The fix

Move the inventory log into its own modal, reusing the existing `Dialog`
components already used for the view/restock/adjust modal on this page:

- Add an "Inventory Log" button near the top of the page (next to the search
  input), separate from the per-service row actions.
- Clicking it opens a new `Dialog` containing the existing `InventoryLogTable`
  content (table + pagination), unchanged in behavior.
- The log's own pagination state (`page`) should reset when the modal closes,
  same as the existing service action modal resets its form state on close.
- Keep this as its own `Dialog` instance, independent from the existing
  view/restock/adjust `Dialog` on the page (both can't be meaningfully open at
  once since they're triggered from different buttons, but they must not share
  open state).
- Do not change `useInventoryLogs`, the API layer, or the table's columns/data.

## Build steps

1. [x] In `InventoryPage.tsx`, add local state for the log modal's open/closed
   status, add the "Inventory Log" trigger button, and wrap the existing
   `InventoryLogTable` render in a `Dialog`/`DialogContent` with a
   `DialogHeader`/`DialogTitle` ("Inventory log") and a close action, removing
   it from its current inline placement at the bottom of the page.
   **Done when:** the log no longer renders inline; clicking "Inventory Log" opens
   a modal showing the log table with working pagination; closing and
   reopening the modal starts back on page 1.

## Verify

- Open the Inventory tab: the log table is no longer visible on the page by
  default.
- Click "Inventory Log": a modal opens showing the log table with the same
  columns and data as before, and Previous/Next pagination works inside it.
- Close the modal, reopen it: it starts on page 1 again.
- Existing per-service View/Restock/Adjust modal still works independently.

<!-- blueprint:completion {"schemaVersion":1,"specBytes":2263,"specSha256":"8c1c23f69377acd3cd60e0590ec3950ee47058d204e96b3afdaf6d3ca677cd62","branch":"refs/heads/fix/inventory-log-modal","head":"5a610cca9e3a685380b4b821c441aab36981c0d9","baseRef":"refs/heads/master","baseCommit":"5a610cca9e3a685380b4b821c441aab36981c0d9","sourceTree":"8e05ccfd532b7105dea1b01c4dfd98d4d789374c","absentOptional":[]} -->
