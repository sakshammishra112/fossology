# Enhanced Reuse Analysis

This module adds an **enhancedreuser** scheduler agent and a web UI (**Enhanced Reuse Analysis**) that summarizes reuse comparison results: classification histogram, per-file diff/risk, and license comparison between the current upload and the reused upload.

SPDX-License-Identifier: GPL-2.0-only  
SPDX-FileCopyrightText: © 2026 Fossology contributors

## What it does

1. **Runs after normal reuse** — When you schedule reuse with **Enhanced reuse** enabled, the PHP **reuser** job queues **enhancedreuser** only after the concrete **reuser** jobqueue step (`jq_pk`), so copy/reuse data exists before analysis.
2. **Writes analysis to the database** — The C++ agent inserts rows into `enhanced_reuse_*` tables (see `src/www/ui/core-schema.dat`).
3. **Surfaces results in the UI** — From Browse or License view, open **Enhanced Reuse Analysis** (`mod=enhanced_reuse_analysis`) for the current upload and folder. Tabs show histogram, diff tree, and license comparison (server-rendered Twig; no browser JWT required).
4. **Optional REST** — JSON under `/repo/api/v{1|2}/uploads/{uploadId}/enhanced-reuse/...` (authentication as for the rest of the FOSSology API).

## Alignment with the reuser agent

The stock **reuser** (`src/reuser/agent/ReuserAgent.php`) copies **clearing decisions** from the reused upload (`ClearingDao::getFileClearingsFolder`, then `createCopyOfClearingDecision` or, in enhanced reuse mode, `copyClearingDecisionIfDifferenceIsSmall`). It does **not** treat **`license_file`** scanner rows as the thing being reused. **Enhanced reuse analysis** therefore should use the same notion of outcome: **effective clearing conclusions** (as in `PfileDao::getConclusions` / the agent’s clearing query), not scanner fallback—so the report matches what reuse is designed to propagate.

## Layout (this directory)

| Path | Role |
|------|------|
| `agent/` | C++ agent: `enhancedreuser` binary, DB handler, file/license comparison, risk calculation |
| `enhancedreuser.conf` | Scheduler agent definition (`name = enhancedreuser`) |
| `ui/EnhancedReuseAnalysisPlugin.php` | Browse/View menu entry and page controller |
| `ui/agent-enhancedreuser.php` | Agent plugin: queueing with explicit dependency on reuser’s `jq_pk` |
| `ui/services.php` | Registers Twig template path |
| `ui/template/` | Twig/CSS/JS for the analysis page |

Shared PHP:

- `src/lib/php/Dao/EnhancedReuseDao.php` — Reads analysis, stats, diff tree, license rows; can **recompute** license comparison from file pairs + `PfileDao` when stored license rows are empty.
- `src/www/ui/api/Controllers/EnhancedReuseController.php` — REST handlers.
- `src/lib/php/services.xml.in` — Symfony service `dao.enhanced_reuse` (needs `db.manager` and `dao.pfile`). After editing, regenerate/install `services.xml` per your FOSSology build.

## How scheduling works

In `src/reuser/ui/agent-reuser.php`, when the user selects **reuseEnhanced** in the reuse mode flags, `scheduleEnhancedReuseAgent` is set. After `doAgentAdd` succeeds for **agent_reuser**, the code resolves the **agent_enhancedreuser** plugin and calls `scheduleAfterReuserJob($jobId, $uploadId, $jobQueueId, …)`.

`scheduleAfterReuserJob` (in `ui/agent-enhancedreuser.php`) inserts a job with **`[$reuserJqPk]`** as the dependency — not a generic `"agent_reuser"` string — so the scheduler waits for the **specific** reuser queue row. That avoids `JobQueueAdd` treating dependency `0` as “no dependency” if name resolution fails.

## Database model (high level)

- **`enhanced_reuse_analysis`** — One row per run: upload, group, reused upload, timestamps, etc.
- **`enhanced_reuse_file_comparison`** — Per matched file: name, classification, modification, changed lines, risk, pfile ids.
- **`enhanced_reuse_license_comparison`** — Optional per-pair license summary written by the agent.
- **`enhanced_reuse_histogram`** — Optional histogram buckets from the agent.

`EnhancedReuseDao::getLicenseComparison()` returns stored license rows when present; otherwise it builds rows from `enhanced_reuse_file_comparison` pairs (up to 3000), using the same effective license text rules as the agent: **clearing conclusions only** via `PfileDao::getConclusions` (no `license_file` scanner fallback). `NOASSERTION` is shown as empty; explicit **NONE** stays `NONE`.

## Web UI: license comparison and file links

On the **License comparison** tab, each **File** name is a link when an `uploadtree_pk` can be resolved for this upload and the row’s **upload** pfile:

- `UploadDao::getUploadtreeIdFromPfile($uploadId, $uploadPfileFk)`
- URL pattern matches License Browser file links: **`mod=view-license`** with **`upload`** and **`item`** query parameters.

If no tree row exists (or pfile is missing), the name stays plain text. `getUploadtreeIdFromPfile` returns **0** when there is no matching row (safe on empty query results).

## REST endpoints

Under the uploads group (example base: `/repo/api/v1/uploads`):

| Method | Path | Description |
|--------|------|-------------|
| GET | `/{id}/enhanced-reuse/stats` | Histogram-style counts by classification |
| GET | `/{id}/enhanced-reuse/diff-tree` | File-level diff/risk rows |
| GET | `/{id}/enhanced-reuse/license-comparison` | License comparison rows |
| GET | `/{id}/enhanced-reuse/suggestions` | Same diff data sorted by risk (API compatibility) |

Responses are **404** if no analysis exists for that upload in the current group.

## Build and install notes

- **CMake** — `src/CMakeLists.txt` includes `add_subdirectory(enhancedreuser)` so the agent is built with the rest of the agents.
- **Scheduler** — Install `enhancedreuser.conf` and the `enhancedreuser` binary like other agents; enable the module in your deployment if your packaging splits agents.
- **PHP** — Ensure plugins under `enhancedreuser/ui/` are loaded (same mechanism as other UI plugins) and Twig path is registered via `ui/services.php`.

## How to use (operators)

1. Schedule **reuse** from the UI or API, select a package to reuse, and enable **Enhanced reuse** (`reuseEnhanced` / `reuse_enhanced` in API models where applicable).
2. Wait for **reuser** and **enhancedreuser** to finish.
3. Open the upload in Browse or License view, choose **Enhanced Reuse Analysis** from the action menu.
4. Use **Histogram**, **Diff tree**, and **License comparison**; click a file name in **License comparison** to open the standard **view-license** page for that file when a link is available.
