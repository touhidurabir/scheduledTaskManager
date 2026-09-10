# Scheduled Task Manager

A site-administration UI for the scheduled task system in OJS, OMP and OPS 3.5 and later.

`php lib/pkp/tools/scheduler.php list` already answers "what is registered and when does it next
run", but it needs shell access to the server. Most people asking those questions on the PKP forum
are site administrators without it. This plugin puts the same information — plus last-run times,
manual execution and the execution logs — behind Administration.

## What it shows

Administration gains a **Scheduled Tasks** panel leading to
`index.php/index/en/admin/scheduledTasks`, which lists every registered task with:

| Column | Meaning |
|---|---|
| Task | The task's class, as `scheduler.php list` reports it, with the task's own name below |
| Interval | A readable frequency plus the exact cron expression |
| Last Run | When the task last actually ran — see below |
| Due | The next occurrence, resolved in the event's timezone |
| Actions | Run the task now, or list and download its execution logs |

A task that cannot be run right now — switched off by configuration, already running, or
registered without a name — is faded and carries a short badge saying which, so the rows that
can act read first.

### Is anything actually running my tasks?

Three cards above the table answer that before any row is read:

- **Web task runner** — on (and at what interval), off, or *not available* on releases that no
  longer ship one. Its presence is detected, not assumed.
- **Registered tasks**, with the timezone the times are shown in and the config key it came from.
- **Log files**, with the directory they are written to.

While the web task runner is on, a standing notice sits above them recommending the alternative:
run the scheduler from cron instead. The web runner does its work at the end of web requests, so
the cost lands on visitors' page loads and nothing runs at all while nobody is visiting — which is
why it is discouraged beyond small sites.

### Where "Last Run" comes from

Two sources, in order of preference:

1. **Recorded by this plugin.** It listens to the scheduler's own `ScheduledTaskFinished` and
   `ScheduledTaskFailed` events, which both the web task runner and cron dispatch, and stores the
   time, duration and outcome. This only covers runs since the plugin was enabled.
2. **The task's newest log file.** `ScheduledTask` writes
   `{files_dir}/scheduledTaskLogs/{ClassName}-{processId}-{date}.log` on every execution in every
   mode, so this covers runs from before the plugin existed. Clearing the logs from
   Administration also clears this evidence.

A third value, the **task runner checkpoint**, is shown only when it is diagnostic. It is core's
`taskRunnerLastRunSummary` (pkp/pkp-lib#13041, 3.5 only) and is deliberately *not* presented as a
last run: on a task's first sighting the web runner seeds it with a schedule boundary for a task
that has never run. It appears only while that runner is switched on, and only when it stands
clear of the last real execution — a boundary marked covered for an occurrence that did not
happen, which is exactly what to look at when a task seems never to run. Where the runner is off,
or the release no longer has one, the stored checkpoints are leftovers and stay hidden.

### Running a task by hand

Scheduled tasks are not meant to be run manually, so the action asks for confirmation and warns
that long-running tasks may exceed the server's time limit. The plugin refuses to run a task that:

- is switched off by its own `when()`/`skip()` conditions — `ProcessQueueJobs` carries such a
  guard on `queues.process_jobs_at_task_scheduler`, and running it anyway would process the queue
  behind the installation's back;
- is already running, according to the task's `withoutOverlapping()` mutex;
- was registered without a name, and so cannot be identified reliably.

A manual run emits the same lifecycle events the scheduler does, so it is recorded like any other
execution and is visible to any other listener.

## Requirements

- OJS, OMP or OPS 3.5.0 or later
- PHP 8.2 or later
- A site administrator account

## Installation

Downlaod the packaged plugin from release section and installed it. Or Installed from plugin gallery if and when available.

Also possible to do git clone and installed the plugin. Clone/Checkout the repo in `INSTALLATION_PATH/plugins/generic/` and run following commands as

```bash
php INSTALLATION_PATH/lib/pkp/tools/installPluginVersion.php plugins/generic/scheduledTaskManager/version.xml
```

Then enable the plugin:

- **More than one journal/press/server:** Administration → Site Settings → Plugins.
- **Exactly one:** core hides that tab on single-context installations, so the plugin presents
  itself as a context plugin and is enabled from the journal's own Plugins page instead
  (Settings → Website → Plugins). Note that core's plugin grid requires the *Manager* role to
  toggle a context-level plugin, so a site administrator who holds no role in the journal will
  see the row but not the switch.

Either way the page itself stays at the site level and remains site-administrator only.

## Notes and limitations

- **The task list matches cron, not the current request.** In a web request core only registers
  the plugins enabled in that request's context, which at site level would hide every task
  belonging to a journal-level plugin. This page loads plugins from disk exactly as the CLI tools
  do, so what it lists is what `scheduler.php list` lists.
- **Log files are grouped by short class name.** Core names them after the class without its
  namespace, so two task classes sharing a short name across namespaces would share log files.
  That is inherent to core's naming.
- **The log directory is unbounded, and reading it is the plugin's only real cost.** An
  `everyMinute` task writes a file per run — 1,440 a day, tens of thousands in a month, all in one
  flat directory shared by every task.
- Log downloads reuse core's existing site-admin-only
  `admin/downloadScheduledTaskLogFile` operation rather than serving files from the plugin.

## License

MIT. See `LICENSE`.
