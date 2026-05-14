# Enhanced Reuse Analysis

This module adds an **enhancedreuser** scheduler agent and a web UI (**Reuse Analysis**) that provides comprehensive reuse comparison results: classification histogram, per-file diff/risk, detailed license comparison, and actionable insights between the current upload and the reused upload.

SPDX-License-Identifier: GPL-2.0-only  
SPDX-FileCopyrightText: © 2026 Fossology contributors

## What it does

1. **Runs after normal reuse** — When you schedule reuse with **Enhanced reuse** enabled, the PHP **reuser** job queues **enhancedreuser** only after the concrete **reuser** jobqueue step (`jq_pk`), so copy/reuse data exists before analysis.
2. **Writes analysis to the database** — The C++ agent inserts rows into `enhanced_reuse_*` tables (see `src/www/ui/core-schema.dat`).
3. **Surfaces results in the UI** — From Browse or License view, open **Reuse Analysis** (`mod=enhanced_reuse_analysis`) for the current upload and folder. Tabs show histogram, diff tree, and detailed license comparison (server-rendered Twig; no browser JWT required).
4. **Optional REST** — JSON under `/repo/api/v{1|2}/uploads/{uploadId}/enhanced-reuse/...` (authentication as for the rest of the FOSSology API).

## Key Features

### 🔍 **Detailed License Comparison**
- **Side-by-side analysis** with upload vs reuse license counts
- **Smart status indicators** showing license differences (±X)
- **Action-oriented recommendations** (OK, Review, Added, Missing)
- **Clickable counts** that link to detailed file listings
- **Color-coded visualization** for quick risk assessment

### 📊 **Improved Risk Classification**
- **Magnitude-based risk assessment** considering actual change impact
- **Granular thresholds**: ≤20 lines (LOW), 20-100 lines (MEDIUM), >100 lines (HIGH)
- **License conflict detection** for CRITICAL risk scenarios
- **Intelligent modification classification** (MINOR, MAJOR, LICENSE_CHANGED, CONFLICT)

### 📈 **Enhanced User Experience**
- **Clean, modern interface** with redundant titles removed
- **Tabbed navigation** for organized data presentation
- **Responsive design** with Bootstrap styling
- **Intuitive icons and visual indicators** for quick comprehension

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

## Web UI: Enhanced License Comparison and File Links

### **Detailed License Breakdown View**
The **License comparison** tab now provides a comprehensive side-by-side analysis:

```
┌─────────────┬─────────┬─────────┬───────────┬─────────────┐
│   LICENSE   │ UPLOAD  │ REUSE   │   STATUS   │    ACTION   │
├─────────────┼─────────┼─────────┼───────────┼─────────────┤
│ GPL-3.0     │ 234     │ 189     │ ⚠️  -45    │ Review      │
│ MIT         │ 156     │ 142     │ ⚠️  -14    │ Review      │
│ BSD         │ 54      │ 51      │ ✅  -3     │ ✅ OK       │
│ Apache-2.0  │ 89      │ 0       │ ❌  -89    │ ⚠️  Missing  │
│ LGPL-2.1    │ 0       │ 23      │ ❌  +23    │ ⚠️  Added   │
└─────────────┴─────────┴─────────┴───────────┴─────────────┘
```

### **Smart Status Logic**
- **✅ 0**: Perfect match (same count)
- **✅ ±1-5**: Minor differences (acceptable)
- **⚠️ ±6+**: Significant differences (needs review)
- **❌ +X**: License only in reuse package
- **❌ -X**: License only in upload package

### **Action-Oriented Recommendations**
- **✅ OK**: No action needed
- **⚠️ Review**: Significant differences to investigate
- **⚠️ Added**: New license in reuse package
- **⚠️ Missing**: License removed in reuse package

### **Clickable File Listings**
Both **Upload** and **Reuse** count columns are clickable links that redirect to FOSSology's `license_list_files` module, showing detailed file listings for each specific license:

- **URL pattern**: `?mod=license_list_files&item=UPLOADTREE_PK&lic=LicenseName`
- **Root uploadtree resolution**: Automatically finds root folder for proper browsing
- **Zero counts**: Not clickable (no files to show)

### **Visual Improvements**
- **Color-coded differences**: Green (+X), Red (-X), Gray (0)
- **Bold license names** for better readability
- **Bootstrap styling** with responsive design
- **Intuitive icons** for quick status recognition

### **File Name Links**
Each file name in the **Diff tree** tab is a link when an `uploadtree_pk` can be resolved:

- `UploadDao::getUploadtreeIdFromPfile($uploadId, $uploadPfileFk)`
- URL pattern: **`mod=view-license`** with **`upload`** and **`item`** query parameters
- Safe fallback to plain text when no tree row exists

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
3. Open the upload in Browse or License view, choose **Reuse Analysis** from the action menu.
4. Use the three main tabs:
   - **📊 Histogram**: File classification overview
   - **🌳 Diff tree**: Detailed file-by-file comparison with risk assessment
   - **🔍 License comparison**: Detailed license breakdown with actionable insights
5. **Interactive features**:
   - Click license counts to view file listings for specific licenses
   - Review status indicators to identify license compliance issues
   - Use action recommendations to prioritize review tasks

## Recent Improvements (2026)

### **Risk Assessment Enhancement**
- **Magnitude-based classification**: Risk levels now consider actual change impact (number of changed lines)
- **Granular thresholds**: LOW (≤20 lines), MEDIUM (20-100 lines), HIGH (>100 lines)
- **Improved accuracy**: Better correlation between change size and actual risk

### **User Interface Improvements**
- **Cleaner design**: Removed redundant page titles for better UX
- **Enhanced license comparison**: Side-by-side view with status indicators and action recommendations
- **Better navigation**: Tabbed interface with clear visual hierarchy
- **Responsive design**: Optimized for different screen sizes

### **License Analysis Features**
- **Smart difference calculation**: Automatic detection of license count variations
- **Actionable insights**: Clear recommendations (OK, Review, Added, Missing)
- **Visual indicators**: Color-coded status for quick comprehension
- **File drill-down**: Clickable counts leading to detailed file listings

### **Technical Improvements**
- **Database optimization**: More efficient license comparison queries
- **Better error handling**: Graceful fallbacks for missing data
- **Enhanced URL generation**: Correct uploadtree_pk resolution for file browsing
- **Improved performance**: Optimized SQL queries and data processing