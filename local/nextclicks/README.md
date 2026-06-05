# local_nextclicks — Learner Trajectory Plugin for Moodle

A Moodle local plugin that collects learner navigation behaviour (clickstreams), file engagement time, and exposes it alongside Moodle's assessment data through a REST API for Educational Data Mining (EDM) analysis.

Built as part of a Bachelor Thesis on post-processing EDM in Learning Management Systems.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [How Data Collection Works](#how-data-collection-works)
   - [Clickstream Tracking](#clickstream-tracking)
   - [Dwell Time Tracking](#dwell-time-tracking)
6. [Web Service API](#web-service-api)
   - [Authentication](#authentication)
   - [Endpoints](#endpoints)
7. [EDM Analysis Notebook](#edm-analysis-notebook)
   - [Setup](#setup)
   - [Running the Analysis](#running-the-analysis)
   - [Notebook Sections](#notebook-sections)
8. [Database Schema](#database-schema)
9. [Design Decisions](#design-decisions)
10. [Project Structure](#project-structure)

---

## Overview

`local_nextclicks` instruments a Moodle instance to record every learner navigation event and file engagement period. The collected data is exposed through a pre-configured REST web service so that external tools, in this case a Jupyter notebook, can pull it for analysis without any manual Moodle administration.

**What it captures:**

| Signal | How | Where stored |
|---|---|---|
| Page navigation (clickstream) | Moodle event observers | `local_nextclicks_events` |
| Transition counts (A → B) | Derived from consecutive events | `local_nextclicks_trans` |
| File engagement (dwell time) | Client-side JS heartbeat | `local_nextclicks_dwell` |
| H5P xAPI statements | Event observer (`statement_received`) | `local_nextclicks_xapi` |
| Assessment scores | Moodle core grade API (read-only) | Moodle core tables |

**What the analysis produces:**

- Average time spent per activity
- Most common learner navigation paths
- Per-user engagement summary
- Comparison of file dwell time between students who passed vs. failed a quiz in the same section
- H5P xAPI statement analysis — verb distribution, completion/success rates, score distribution, per-user summary

---

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                   Moodle (PHP)                      │
│                                                     │
│  ┌─────────────┐    ┌──────────────────────────┐   │
│  │  Observer   │    │      lib.php hook         │   │
│  │ (events.php)│    │  (injects dwelltracker)   │   │
│  │  · nav      │    └──────────┬───────────────┘   │
│  │  · H5P xAPI │               │                    │
│  └──────┬──────┘               ▼                    │
│         │             ┌──────────────────────┐      │
│         │             │  dwelltracker.js      │      │
│         │             │  (AMD module)         │      │
│         ▼             └──────────┬────────────┘      │
│  ┌─────────────────┐             │ AJAX ping          │
│  │  DB tables      │  ◄──────────┘                   │
│  │  events / trans │                                 │
│  │  last / dwell   │                                 │
│  │  xapi           │                                 │
│  └────────┬────────┘                                 │
│           ▼                                         │
│  ┌─────────────────┐                               │
│  │  external.php   │  ← REST web service           │
│  │  (web service)  │                               │
│  └────────┬────────┘                               │
└───────────┼─────────────────────────────────────────┘
            │  HTTP REST + token
            ▼
┌─────────────────────────┐
│  trajectory_analysis    │
│  .ipynb  (Python)       │
│  pandas / matplotlib    │
└─────────────────────────┘
```

---

## Requirements

| Component | Version |
|---|---|
| Moodle | 4.4 or later |
| PHP | 8.1 or later |
| MySQL | 8.0 or later (MariaDB 10.6+ also works) |
| Python (for notebook) | 3.9 or later |
| Docker + Docker Compose | Any recent version |

Python packages required for the notebook:

```
requests
pandas
matplotlib
python-dotenv
notebook
```

---

## Installation

### Using Docker

The repository includes a pre-configured Docker environment.

**1. Clone and start:**

```bash
git clone <repo-url>
cd moodle
docker compose up -d
```

Moodle will be available at `http://localhost:8081`.

**2. Complete the Moodle installer** (first run only):

Open `http://localhost:8081` in a browser and follow the on-screen installer. Use these database credentials when prompted:

| Field | Value |
|---|---|
| Database host | `db` |
| Database name | `moodle` |
| Database user | `moodle` |
| Database password | `moodle` |

**3. Install the plugin:**

After Moodle is set up, run:

```bash
docker exec moodle-moodle-1 php /var/www/html/admin/cli/upgrade.php --non-interactive
```

The plugin installs automatically. As part of installation it:
- Enables Moodle web services and the REST protocol
- Generates a permanent API token for the site admin
- Stores the token in plugin config

**4. Retrieve the token:**

```bash
docker exec moodle-moodle-1 php -r "
  define('CLI_SCRIPT', true);
  require('/var/www/html/config.php');
  echo get_config('local_nextclicks', 'apitoken') . PHP_EOL;
"
```

Or visit **Admin → Local plugins → Nextclicks** in the Moodle UI.

**5. Configure the notebook:**

Create `testing/.env`:

```env
MOODLE_URL=http://localhost:8081
MOODLE_TOKEN=<token from step 4>
```

### Manual installation (production Moodle)

1. Copy the `local/nextclicks` folder into your Moodle's `local/` directory.
2. Log in as admin and go to **Site administration → Notifications** to trigger the upgrade.
3. The plugin auto-enables web services and generates the token — no manual configuration needed.
4. Retrieve the token from **Admin → Local plugins → Nextclicks**.

---

## How Data Collection Works

### Clickstream Tracking

The plugin registers two Moodle event observers (highest priority, 9999) that fire on every page load:

| Event | Handler |
|---|---|
| `\core\event\course_viewed` | `observer::handle_course_viewed` |
| `\core\event\course_module_viewed` | `observer::handle_coursemodule_viewed` |

On each event the observer:

1. **Logs the event** — inserts one row into `local_nextclicks_events` with the user, course, item type (`course` or `cm`), item ID, and timestamp. This table is the raw trajectory used for EDM.

2. **Records the transition** — looks up the previous item from `local_nextclicks_last`. If there was a previous visit within the same session (≤ 30 minutes ago), it increments the transition count in `local_nextclicks_trans` (or inserts a new row).

3. **Updates the last-seen record** — overwrites `local_nextclicks_last` for this user+course with the current item.

**Deduplication:** transitions seen within an 8-second window for the same user+course+pair are silently dropped. This prevents browser refreshes and back-button navigation from inflating counts.

**Session boundary:** a gap of more than 30 minutes between two events is treated as a new session. The transition is not recorded across that gap because the learner likely left and came back later.

**Edit-mode noise:** events triggered while Moodle's course editing mode is active (`?edit=on`) are ignored, since those page views are administrative rather than learner behaviour.

### Dwell Time Tracking

For `mod_resource` (file) pages, `lib.php` injects the AMD JavaScript module `local_nextclicks/dwelltracker` on every page load. The module:

1. Sets up a **10-second interval** that checks whether the learner is actively engaged with the page. Active means: tab is visible (`document.visibilityState === 'visible'`) AND the window has focus (`document.hasFocus()`).

2. Every 10 seconds, if active, it sends a small AJAX call to `local_nextclicks_track_dwell` with the elapsed seconds. Each ping is capped at 120 seconds to guard against runaway values.

3. A final flush is sent when the tab becomes hidden (`visibilitychange`) or the page is about to unload (`beforeunload`).

This produces a series of small dwell records in `local_nextclicks_dwell`. The analysis notebook aggregates them per user per file.

---

## Web Service API

### Authentication

All API calls use a permanent Moodle web service token passed as the `wstoken` query parameter. The token is auto-generated during plugin installation and stored in plugin config.

Base URL:
```
http://<moodle-host>/webservice/rest/server.php
```

All requests must include:
```
?wstoken=<token>&moodlewsrestformat=json
```

### Endpoints

#### `local_nextclicks_get_trajectories`

Returns individual learner navigation events ordered by user and timestamp. Used as the primary input for EDM analysis.

**Parameters (all optional):**

| Parameter | Type | Default | Description |
|---|---|---|---|
| `courseid` | int | 0 | Filter to a specific course. 0 = all courses. |
| `userid` | int | 0 | Filter to a specific user. 0 = all users. |
| `since` | int | 0 | Only return events after this Unix timestamp. |

**Response fields:**

| Field | Type | Description |
|---|---|---|
| `id` | int | Event record ID |
| `userid` | int | Moodle user ID |
| `courseid` | int | Course ID |
| `itemtype` | string | `"course"` or `"cm"` |
| `itemid` | int | Course ID (for course views) or cmid (for activity views) |
| `timecreated` | int | Unix timestamp of the navigation event |
| `timespent` | int | Seconds until the learner's next event in this course (0 if unknown or last in session, capped at 1800) |

`timespent` is computed server-side using SQL `LEAD()` — it is the gap to the next event for the same user+course, capped at 30 minutes. A value of 0 means this was the last event in the session.

**Example:**
```
GET /webservice/rest/server.php
  ?wstoken=TOKEN
  &wsfunction=local_nextclicks_get_trajectories
  &moodlewsrestformat=json
  &courseid=2
  &since=1700000000
```

---

#### `local_nextclicks_get_file_dwell`

Returns aggregated dwell time (seconds) per user for a specific file resource.

**Parameters:**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | yes | Course ID |
| `cmid` | int | yes | Course module ID of the file resource |
| `userid` | int | no | Filter to a specific user (0 = all) |
| `since` | int | no | Lower timestamp bound |
| `until` | int | no | Upper timestamp bound (useful for cutting off after quiz deadline) |

**Response:**

Array of `{ userid, dwellseconds }` — one row per user who has dwell records.

---

#### `local_nextclicks_track_dwell`

Write endpoint called by the client-side `dwelltracker.js`. Not intended for external use.

**Parameters:** `cmid` (int), `seconds` (int, clamped to 1–120 server-side)

**Response:** `true` on success, `false` if skipped (guest user, non-resource module).

---

#### `local_nextclicks_get_xapi_statements`

Returns xAPI statements captured from H5P activities, one row per statement. Each statement represents a single learner interaction (a question answered, an activity completed) with its verb, score, completion, success, and duration.

**Parameters (all optional):**

| Parameter | Type | Default | Description |
|---|---|---|---|
| `courseid` | int | 0 | Filter to a specific course. 0 = all courses. |
| `userid` | int | 0 | Filter to a specific user. 0 = all users. |
| `cmid` | int | 0 | Filter to a specific H5P activity. 0 = all activities. |
| `since` | int | 0 | Only return statements after this Unix timestamp. |

**Response fields:**

| Field | Type | Description |
|---|---|---|
| `id` | int | Statement record ID |
| `userid` | int | Moodle user ID |
| `courseid` | int | Course ID |
| `cmid` | int | H5P activity course module ID |
| `verb` | string | xAPI verb local name — `answered` or `completed` |
| `objectid` | string | xAPI object ID — identifies the specific sub-content within the H5P activity |
| `completion` | int | 1 if the learner completed this interaction |
| `success` | int | 1 if the learner succeeded |
| `score_raw` | int | Raw score (0 if not reported) |
| `score_min` | int | Minimum possible score (0 if not reported) |
| `score_max` | int | Maximum possible score (0 if not reported) |
| `duration_seconds` | int | Interaction duration in seconds (0 if not reported) |
| `timecreated` | int | Unix timestamp when the statement was received |

> **Note on verbs:** Moodle's H5P module only passes statements with `answered` or `completed` verbs through to the event system. Other verbs (e.g. `progressed`) are filtered out by the H5P xAPI handler before the event fires.

---

#### Bundled Moodle core functions

The `learner_trajectory_api` service also exposes these Moodle core functions under the same token:

| Function | Used for |
|---|---|
| `core_course_get_contents` | Listing course sections and activities |
| `gradereport_user_get_grade_items` | Fetching quiz and course grades per user |
| `mod_quiz_get_quizzes_by_courses` | Getting quiz attempt limits |
| `mod_quiz_get_user_attempts` | Getting individual user attempt records |

---

## EDM Analysis Notebook

The analysis notebook is located at `testing/trajectory_analysis.ipynb`.

### Setup

**Install Python dependencies:**

```bash
pip install requests pandas matplotlib python-dotenv notebook
```

**Create the `.env` file** in the `testing/` directory:

```env
MOODLE_URL=http://localhost:8081
MOODLE_TOKEN=<your token>
```

**Open the notebook** in VS Code (recommended) or Jupyter:

```bash
cd testing
python3 -m notebook
```

Select the Python 3.9+ kernel and run cells from top to bottom.

### Running the Analysis

- Sections 1–4 work as soon as learners have browsed Moodle (at least a few page views per user).
- Section 5 requires a course with at least one file resource and one quiz, and at least one student submission.
- Section 6 requires at least one H5P activity with learner interactions. If no H5P content types are installed yet, run the scheduled task first (see Installation).
- If data is missing the notebook prints a descriptive message and skips to the next section.

### Notebook Sections

#### 1. Fetch Trajectory Data

Calls `local_nextclicks_get_trajectories` and loads results into a pandas DataFrame. An optional course dropdown (requires `ipywidgets`) lets you filter to a single course; otherwise set `SELECTED_COURSEID = <id>` manually.

#### 2. Time Spent per Activity

Groups events by activity label (`course:<id>` or `cm:<id>`) and computes visit count, average seconds, and total seconds. Only events where `timespent > 0` are included (last-in-session events have unknown duration).

#### 3. Navigation Sequences

Reconstructs each learner's full navigation path and extracts two-step transitions. The top 10 most frequent transitions are shown as a horizontal bar chart. This reveals which activity pairs are most commonly visited back-to-back across all learners.

#### 4. Per-User Summary

Aggregates per learner: total events, unique activities visited, total time in seconds, first and last seen timestamps, and session span. Useful for identifying highly engaged vs. disengaged learners.

#### 5. Quiz Pass vs File Time (same section)

Selects a course section, identifies the file resource and quiz in that section, and separates students into "Passed" and "Failed" groups based on their quiz grade. Computes average file engagement time per group and plots a bar chart. The file time is cut off at the quiz deadline (if set) or at the student's last allowed attempt.

File engagement time is taken from dwell pings where available (accurate), and falls back to trajectory `timespent` when no dwell data exists (e.g. the file opens in a new tab). The notebook prints a data source summary after the result table and labels the chart accordingly — if trajectory fallback was used the chart title shows a warning, since `timespent` overestimates reading time by absorbing idle time between closing the file and the next Moodle click.

---

#### 6. H5P xAPI Statements

Calls `local_nextclicks_get_xapi_statements` and produces three side-by-side charts: verb distribution (how many `answered` vs `completed` statements), completion and success rates among completed interactions, and a score percentage histogram for answered questions. A per-user summary table shows total statements, unique H5P activities visited, total completions and successes, average raw score, and total interaction duration per learner.

Before running, set these four variables at the top of the cell to match the course you want to analyse:

```python
ANALYSIS_COURSEID  = ?   # the course ID you want to analyse
ANALYSIS_SECTIONID = ?   # the section number within that course (1, 2, 3 …)
ANALYSIS_QUIZ_ITEMID = ? # the cmid of the quiz in that section
ANALYSIS_FILE_ITEMID = ? # the cmid of the file resource in that section
```

To find the right IDs, run the cell once with just `ANALYSIS_COURSEID` set, the cell will print a table listing every quiz and file resource in the course along with their section numbers and cmids. Use those values to fill in the remaining three variables and re-run.

> **Example:** a teacher running analysis on course 5, section 2, quiz cmid 18, file cmid 14 would set:
> ```python
> ANALYSIS_COURSEID  = 5
> ANALYSIS_SECTIONID = 2
> ANALYSIS_QUIZ_ITEMID = 18
> ANALYSIS_FILE_ITEMID = 14
> ```

---

## Database Schema

### `local_nextclicks_events`

Raw event log — one row per page view.

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `userid` | int | Moodle user ID |
| `courseid` | int | Course ID |
| `itemtype` | char(20) | `course` or `cm` |
| `itemid` | int | cmid (for `cm`) or courseid (for `course`) |
| `timecreated` | int | Unix timestamp |

Index: `(userid, courseid)`

### `local_nextclicks_trans`

Aggregated transition counts.

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `courseid` | int | Course ID |
| `source` | char(50) | Source item key, e.g. `cm:12` |
| `target` | char(50) | Target item key, e.g. `course:3` |
| `cnt` | int | Number of times this transition was observed |
| `timemodified` | int | Last update timestamp |

### `local_nextclicks_last`

Last-seen item per user per course — used internally by the observer to determine the source of the next transition.

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `userid` | int | Moodle user ID |
| `courseid` | int | Course ID |
| `itemtype` | char(20) | `course` or `cm` |
| `itemid` | int | Item identifier |
| `timecreated` | int | Timestamp of this view |

### `local_nextclicks_dwell`

File engagement pings from the client-side dwell tracker.

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `userid` | int | Moodle user ID |
| `courseid` | int | Course ID |
| `cmid` | int | Course module ID (file resource) |
| `seconds` | int | Active seconds in this ping (1–120) |
| `timecreated` | int | Unix timestamp of the ping |

Index: `(courseid, cmid, userid)`

### `local_nextclicks_xapi`

xAPI statements received from H5P activities — one row per statement, captured in real time by the event observer.

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `userid` | int | Moodle user ID |
| `courseid` | int | Course ID |
| `cmid` | int | H5P activity course module ID |
| `verb` | char(100) | xAPI verb local name — `answered` or `completed` |
| `objectid` | char(255) | xAPI object ID — identifies sub-content within the H5P activity |
| `completion` | int(1) | 1 if the learner completed this interaction |
| `success` | int(1) | 1 if the learner succeeded |
| `score_raw` | int | Raw score (0 if not reported) |
| `score_min` | int | Minimum possible score (0 if not reported) |
| `score_max` | int | Maximum possible score (0 if not reported) |
| `duration_seconds` | int | Interaction duration in seconds, parsed from ISO 8601 (0 if not reported) |
| `timecreated` | int | Unix timestamp when the statement was received |

Index: `(userid, courseid)`

---

## Design Decisions

**8-second deduplication window**
The observer deduplicates transitions seen within 8 seconds for the same source→target pair in a session. This handles browser refreshes, back-button bounces, and accidental double-clicks without losing real transitions. The window is stored in `$SESSION` (server-side, no client dependency).

**30-minute session boundary**
A gap of more than 1800 seconds between two events is treated as a new session. This follows the standard web analytics convention for session timeout. Transitions across this gap are not recorded because the learner likely closed the browser and returned later, the two visits are behaviourally unrelated.

**`timespent` computed via SQL LEAD()**
Rather than computing time-on-page client-side, the server derives it post-hoc from the event log using `LEAD(timecreated) OVER (PARTITION BY userid, courseid ORDER BY timecreated)`. This is accurate for server-rendered pages and requires no JS instrumentation beyond what Moodle already does. Dwell time from `dwelltracker.js` complements this for file resources where the learner stays on the same page.

**Dwell pings capped at 120 seconds**
Each dwell ping covers at most 120 seconds of active time. This prevents a single stuck tab from producing unrealistically large dwell values. On the server, the same cap is enforced in `track_dwell()`.

**Dwell tracking limitation: PDFs opened in a new tab**
`dwelltracker.js` relies on `document.hasFocus()` and `document.visibilityState` to determine whether the learner is actively engaged. When a file resource opens in a new tab (the Moodle default for PDFs), the Moodle page immediately loses focus and visibility — so no dwell pings are sent, and `local_nextclicks_dwell` records zero seconds for the entire PDF session.

The trajectory `timespent` field (computed via `LEAD()`) is not a reliable substitute: it measures the gap between the PDF click and the learner's next navigation event in Moodle, which absorbs any idle time between finishing the PDF and returning to the course. A learner who reads a PDF for 3 minutes and then takes a 20-minute break before clicking the next activity produces a `timespent` of ~23 minutes. The result is a systematic underestimate in the dwell table and a systematic overestimate in the trajectory table — neither gives accurate PDF reading time.

To enable accurate dwell tracking, a teacher can change the file resource's display setting to **Embed**, which renders the PDF inside the Moodle page frame and keeps the tracker active. This cannot be enforced by the plugin and requires a per-resource configuration choice.

**Token generated for site admin, not a dedicated service account**
The API token is generated for the primary site administrator because the admin already holds all required capabilities. This keeps installation to a single step. For a production deployment with multiple admins, consider creating a dedicated service account with only the `local/nextclicks:viewtrajectories` capability.

**Auto-enabling web services on install**
The plugin calls `set_config('enablewebservices', 1)` and adds `rest` to `webserviceprotocols` during installation. This is necessary for the token to function and removes the most common post-install manual step. It uses Moodle's public config API, no core files are modified.

**xAPI capture via event observer, not direct LRS integration**
H5P activities send xAPI statements through Moodle's internal xAPI pipeline. Rather than implementing a standalone Learner Record Store (LRS) — a system-agnostic service that receives xAPI statements over a standardised REST API — the plugin registers an observer for `\mod_h5pactivity\event\statement_received`. This event fires after Moodle has already validated the statement and saved it to its own H5P tables. The observer extracts verb, object, result, and duration from the minified statement and stores them in `local_nextclicks_xapi`, making the data available through the same token-authenticated API as the rest of the plugin. The approach is Moodle-specific but requires no additional infrastructure and stays consistent with how the rest of the plugin works. Moodle's H5P handler filters statements to `answered` and `completed` verbs before firing the event, so only those two verbs appear in the table.

---

## Project Structure

```
local/nextclicks/
├── version.php                  Plugin metadata and version
├── lib.php                      Page hook — injects dwelltracker JS on file pages
├── settings.php                 Admin settings page — displays auto-generated token
│
├── lang/
│   └── en/
│       └── local_nextclicks.php English language strings
│
├── db/
│   ├── install.xml              Database table definitions
│   ├── install.php              Post-install hook — triggers token generation
│   ├── upgrade.php              Database migration steps
│   ├── access.php               Capability definitions
│   ├── services.php             Web service and function declarations
│   └── events.php               Event observer registrations
│
├── classes/
│   ├── external.php             Web service implementation (get_trajectories, track_dwell, get_file_dwell, get_xapi_statements)
│   ├── observer.php             Event handlers — records nav events, transitions, and H5P xAPI statements
│   └── setup.php                Installation helper — token generation and web service setup
│
└── amd/
    ├── src/
    │   └── dwelltracker.js      Client-side dwell tracker (source)
    └── build/
        └── dwelltracker.min.js  Minified production build

testing/
├── trajectory_analysis.ipynb   Jupyter notebook — EDM analysis
├── trajectory_analysis.html    Static HTML export of notebook output
└── .env                        Local config — MOODLE_URL and MOODLE_TOKEN

docker/
└── Dockerfile                  PHP 8.2 + Apache image for Moodle

docker-compose.yml               Defines moodle + db services
config.php                       Moodle runtime configuration (DB, wwwroot)
```
