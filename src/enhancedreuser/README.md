# Enhanced Reuser Agent

## Overview

The **Enhanced Reuser Agent** is a new FOSSology module that addresses the gap between the
existing `reuser` agent (which mechanically copies clearing decisions) and what users actually
need when evaluating a new version of a previously-cleared package: **understanding what
changed and why**.

This agent runs after all scanner agents have completed, compares the old cleared upload (v1)
with the new upload (v2), and produces:

- A **diff tree view** showing per-file change status with line-level details
- A **license histogram comparison** (v1 vs v2) with colour-coded change indicators
- **Aggregate statistics** including risk-level assessment
- **Smart Reuse Suggestions** — an automatically ranked list of previously-cleared uploads
  that closely match the current upload
- A **bulk decision panel** allowing the user to apply clearing decisions in bulk
  based on file change type

---

## Directory Structure

```
src/enhancedreuser/
├── agent/
│   ├── CMakeLists.txt              Build rules for the scheduler agent
│   ├── DiffAnalyzer.php            Wraps the diff tool; classifies line changes
│   ├── EnhancedReuserAgent.php     Core agent class; orchestrates the analysis pipeline
│   ├── enhancedreuser.php          Scheduler entry point
│   ├── LicenseChangeAnalyzer.php   Compares license histograms between v1 and v2
│   ├── SmartSuggestionEngine.php   Ranks cleared uploads by filename similarity
│   └── version.php.in              Version template (filled by CMake)
├── api/
│   └── Controllers/
│       └── EnhancedReuserController.php  REST API controller
├── ui/
│   ├── EnhancedReuserPlugin.php    DefaultPlugin rendering the dashboard page
│   └── template/
│       ├── enhanced_reuser.html.twig     Main dashboard HTML
│       └── enhanced_reuser.js.twig       All JavaScript (AJAX, Chart.js, table rendering)
├── CMakeLists.txt                  Top-level CMake module definition
├── enhancedreuser.conf             FOSSology scheduler config
└── README.md                       This file
```

---

## Architecture

### Agent (`agent/`)

The `EnhancedReuserAgent` runs as a standard FOSSology scheduler agent. It is triggered
when an upload has a configured reuse pair (set by the existing `reuser` agent UI at
upload time). The agent:

1. Looks up the reused-upload pair via `UploadDao::getReusedUpload()`
2. For each pair, delegates to three helper classes:
   - **`DiffAnalyzer`**: Invokes the system `diff` tool between the v1 and v2 file paths
     (obtained via `TreeDao::getRepoPathOfPfile()`). Classifies changes as:
     - `identical` — no change
     - `comment_only` — all changed lines are comments/blank lines
     - `minor` — ≤ 10 total changed lines
     - `major` — > 10 changed lines
   - **`LicenseChangeAnalyzer`**: Queries `LicenseDao::getLicenseHistogram()` and
     `ClearingDao::getClearedLicenseIdAndMultiplicities()` for both uploads. Produces a
     side-by-side comparison with per-license change status (added / removed / changed /
     unchanged) and an overall risk level.
   - **`SmartSuggestionEngine`**: Scores all accessible uploads using a weighted
     combination of Levenshtein distance on the package base-name and token-set (Jaccard)
     similarity on the full filename. Returns the top-10 matches.
3. Serialises the full analysis result to
   `$FOSSOLOGY_PATH/enhanced-reuse/{uploadId}/{reusedUploadId}/analysis.json`.

The JSON cache avoids re-running expensive diffs on every REST call.

### REST API (`api/Controllers/EnhancedReuserController.php`)

All endpoints are under `/repo/api/v{1,2}/uploads/{id}/enhanced-reuse/`.

The controller first checks the JSON cache from the agent; if absent, it computes the
result on-demand (so the UI works even if the agent has not been run yet).

| Method | Path | Description |
|--------|------|-------------|
| `GET`  | `/diff-tree` | Per-file diff entries |
| `GET`  | `/stats` | Aggregate statistics |
| `GET`  | `/license-comparison` | Side-by-side license histogram |
| `GET`  | `/suggestions` | Smart reuse candidates list |
| `POST` | `/decide` | Bulk clearing decision |

All `GET` endpoints accept an optional `?reuseUploadId=<id>` query parameter. If omitted,
the controller falls back to the DB-stored reuse pair for that upload.

#### `GET /diff-tree`

Returns an array of file diff entries. Each entry includes:

```json
{
  "fileName": "src/parser.c",
  "v2ItemId": 4501,
  "v2PfileId": 1102,
  "v1ItemId": 3321,
  "v1PfileId": 980,
  "fileStatus": "modified_minor",
  "linesAdded": 3,
  "linesRemoved": 1,
  "commentLinesChanged": 0,
  "codeLinesChanged": 4,
  "diffLevel": 4,
  "diffType": "minor",
  "licenseStatus": "unchanged",
  "hasDecisionInV1": true
}
```

`fileStatus` values:
- `identical` — same file hash in both versions
- `new` — file exists in v2 only
- `deleted` — file existed in v1 but not v2
- `modified_minor` — ≤ 10 changed lines
- `modified_major` — > 10 changed lines
- `modified_comment_only` — only comment/blank lines changed

`licenseStatus` values:
- `unchanged` — same license decisions in both versions
- `license_added` — new licenses in v2
- `license_removed` — licenses cleared in v1 no longer present in v2
- `license_changed` — licenses both added and removed
- `unknown` — no decisions in either version

#### `GET /stats`

```json
{
  "totalFiles": 240,
  "identicalFiles": 180,
  "modifiedFiles": 45,
  "modifiedMinorFiles": 30,
  "modifiedMajorFiles": 12,
  "commentOnlyFiles": 3,
  "newFiles": 10,
  "deletedFiles": 5,
  "filesWithNewLicense": 2,
  "filesWithRemovedLicense": 0,
  "filesWithChangedLicense": 1,
  "totalLinesAdded": 312,
  "totalLinesRemoved": 88,
  "pctIdentical": 75.0,
  "pctModified": 18.8,
  "pctNew": 4.2,
  "pctDeleted": 2.1,
  "riskLevel": "high",
  "pctNewLicenses": 33.3
}
```

**Risk level** is determined as:
- `high` — at least one new license type appeared in v2 that was not in v1
- `medium` — existing license counts changed or licenses were removed
- `low` — no license changes; only file content or comment changes

#### `GET /license-comparison`

```json
{
  "added": ["Apache-2.0"],
  "removed": [],
  "unchanged": ["GPL-2.0-only", "MIT"],
  "changed": ["LGPL-2.1-only"],
  "riskLevel": "high",
  "v1Total": 3,
  "v2Total": 4,
  "pctNewLicenses": 33.3,
  "comparison": [
    {
      "name": "GPL-2.0-only",
      "v1Count": 120,
      "v2Count": 120,
      "status": "unchanged",
      "colour": "#6c757d"
    },
    {
      "name": "Apache-2.0",
      "v1Count": 0,
      "v2Count": 8,
      "status": "added",
      "colour": "#28a745"
    }
  ]
}
```

The `colour` field is used by the Chart.js histogram directly.

#### `GET /suggestions`

```json
[
  {
    "uploadId": 17,
    "filename": "libfoo-2.1.0.tar.gz",
    "score": 88,
    "uploadedAt": "2024-11-15T10:32:00+00:00",
    "status": "Closed",
    "groupId": 1
  }
]
```

Suggestions are ranked by `score` (0–100). The score is a weighted combination of:
- **60%** normalised Levenshtein distance on the base package name (version/extension stripped)
- **40%** Jaccard index on full-filename tokens

#### `POST /decide`

Request body (JSON):

```json
{
  "reuseUploadId": 17,
  "scope": "comment_only_files",
  "action": "reuse"
}
```

Valid `scope` values:
- `identical_files` — files with identical hash
- `comment_only_files` — files where only comments/blank lines changed
- `changed_files` — all modified files (minor + major + comment)
- `new_files` — files not present in v1

Valid `action` values:
- `reuse` — copy the v1 clearing decision into the v2 item
- `tbd` — create a "Work in Progress" (WIP) decision (requires manual review)
- `skip` — no decision applied

Response:

```json
{
  "message": "Bulk decision applied",
  "processed": 32,
  "skipped": 1,
  "scope": "comment_only_files",
  "action": "reuse"
}
```

---

## UI Dashboard

Access the dashboard at:

```
https://<fossology-host>/repo/?mod=enhanced_reuser&upload=<uploadId>
```

### Dashboard Sections

#### 1. Overview Statistics

Eight stat cards showing:
- Total files, identical, modified, new, deleted
- Total lines added / removed
- % of new license types (relative to v1)
- Risk level badge (🟢 LOW / 🟠 MEDIUM / 🔴 HIGH)

#### 2. License Histogram

A Chart.js grouped bar chart displaying v1 and v2 scanner-detected license counts side by
side for each license type. Bars are colour-coded:

| Colour | Meaning |
|--------|---------|
| 🟢 Green (`#28a745`) | License **added** in v2 |
| 🔴 Red (`#dc3545`) | License **removed** in v2 |
| 🟠 Amber (`#fd7e14`) | License count **changed** |
| ⬛ Grey (`#6c757d`) | License **unchanged** |

#### 3. Smart Suggestions Panel

Automatically suggests previously-cleared uploads that match the current upload by
filename similarity. Each entry shows:
- Upload filename with a truncated display
- Similarity score badge (green ≥ 70%, amber ≥ 40%, grey < 40%)
- Upload ID, status, and date
- "Use this" link to open the dashboard comparing against that upload

#### 4. Diff Tree View

A filterable table listing every file in v2 (plus deleted files from v1), showing:

| Column | Description |
|--------|-------------|
| File | Filename (monospace) |
| Status | Colour-coded badge |
| + | Lines added |
| − | Lines removed |
| 💬 | Comment lines changed |
| ⟨/⟩ | Code lines changed |
| License | License status |
| v1 Decision | Whether a clearing decision exists in v1 |
| Actions | "View" link to the file's license review page |

**Row colours:**
- White/light-green: new files
- Light-red: deleted files
- Light-cyan: minor modifications
- Light-amber: major modifications
- Light-blue: comment-only modifications

Filter buttons at the top of the panel allow narrowing the view to a specific change
category.

#### 5. Bulk Decision Panel

Select a scope and action, then click **Apply Bulk Decision**. The panel shows
a confirmation result with the number of files processed and skipped.

---

## Integration with Existing Reuser Agent

The Enhanced Reuser Agent is **complementary** to the existing `reuser` agent:

1. User selects "Reuse of License Clearing" at upload time (existing workflow), choosing
   a base upload and reuse mode.
2. The existing `reuser` agent copies decisions.
3. The **Enhanced Reuser Agent** runs after all scanners finish and writes the analysis
   JSON to disk.
4. The user navigates to the Enhanced Reuse Dashboard to review what changed, examine the
   license histogram, and optionally apply bulk decisions for the remaining undecided files.

The `SmartSuggestionEngine` also surfaces relevant candidates **proactively** — even if
the user did not configure a reuse pair at upload time — by scanning all visible uploads
for filename similarity.

---

## Build & Install

### CMake

Add to the top-level `src/CMakeLists.txt`:

```cmake
add_subdirectory(enhancedreuser)
```

Then rebuild normally:

```bash
mkdir -p build && cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
make install
```

### Composer Autoloading

The new namespaces must be registered in `src/composer.json`:

```json
"autoload": {
  "psr-4": {
    "Fossology\\EnhancedReuser\\":      "enhancedreuser/",
    "Fossology\\EnhancedReuser\\Api\\": "enhancedreuser/api"
  }
}
```

Run `composer dump-autoload` after modifying `composer.json`.

### REST Routes

The REST routes are registered in `src/www/ui/api/index.php` under the
`/uploads/{id}/enhanced-reuse/` prefix.

### Twig Template Path

`EnhancedReuserPlugin` uses `enhanced_reuser.html.twig` which must be in a directory
registered with the Twig loader. The CMake install puts templates under:

```
$FO_MODDIR/enhancedreuser/ui/template/
```

The Twig loader path should include this directory (configured in `src/lib/php/bootstrap.php`
or the application bootstrap) or the templates can be symlinked into the standard template
search path.

---

## Data Storage

Analysis results are stored on the file system (no DB schema changes required):

```
$FOSSOLOGY_PATH/enhanced-reuse/
└── {uploadId}/
    └── {reusedUploadId}/
        └── analysis.json
```

The JSON file contains the full analysis: `stats`, `licenseComparison`, `diffTree`, and
`suggestions`. The REST API reads this cache first; if absent, it computes on-demand
(slightly slower for large uploads).

To force re-analysis, delete the JSON file and re-run the Enhanced Reuser agent via the
scheduler (or trigger it manually through the FOSSology job queue UI).

---

## Risk Level Decision Logic

| Condition | Risk Level |
|-----------|-----------|
| One or more new license types detected in v2 that were not in v1 | **HIGH** |
| Existing license counts changed, or licenses removed from v2 | **MEDIUM** |
| No license type changes; only file content or comment-only diffs | **LOW** |

---

## Smart Suggestions — Similarity Scoring

The `SmartSuggestionEngine` scores each candidate upload `C` against the target upload `T`:

1. **Extract base name**: strip archive extensions (`.tar.gz`, `.zip`, …) and version
   suffixes (`-1.2.3`, `_v2.0`, …) from the filename.
2. **Levenshtein score** (60% weight): `1 − lev(base_T, base_C) / max(len(base_T), len(base_C))`
3. **Token Jaccard score** (40% weight): split both full filenames on `[\W_\-\.]+`,
   compute `|tokens_T ∩ tokens_C| / |tokens_T ∪ tokens_C|`
4. **Combined score** = `round(0.6 × levScore + 0.4 × tokenScore) × 100` (0–100)

Only candidates with score > 0 are returned, sorted descending by score then by upload
timestamp (newer first).

---

## Limitations & Future Work

- **In-memory diff tree for large uploads**: For uploads with thousands of files the
  diff tree computation can take several seconds. The agent pre-computation mitigates
  this; a progress indicator is shown in the UI while the AJAX calls resolve.
- **DB-backed cache**: The current JSON file cache is simple but not queryable. A future
  version could store results in a dedicated table for filtering/reporting across uploads.
- **SPDX risk matrix**: Future enhancement to cross-reference detected licenses against
  a user-configured risk policy (e.g., "GPL-3.0-only is high risk in proprietary contexts").
- **Side-by-side diff viewer**: The diff tree shows line counts but not the actual diff
  text. A future enhancement would inline the unified diff for each file.
- **Agent UI integration**: The Enhanced Reuser Dashboard should be accessible directly
  from the "Browse" upload page via a dedicated tab or button, not just via direct URL.
