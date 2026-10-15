# Scheduling imports

Status: **a design.** The engine is built so that this works without further
code. The manifests in `deploy/` (see [deploy.md](deploy.md)) put it into
practice and have run on a local k3d cluster; the sketch below is the shorter
version, and has not run anywhere else. It is the
"design for, but do not build" item of the plan (requirement 7).

## What the engine gives a scheduler

The engine does not schedule. Whatever starts a run decides when
([ADR 0012](decisions/0012-running-imports.md)). Everything a scheduler needs
is there:

- **A command per unit of work.** `drush import:run <import>` and
  `drush import:run-set <set>` start a run (or continue the one that is not
  over) and work until it is over or a time budget (`--max-time`) is spent.
  `drush import:work --pool=<pool> [--once]` processes the queued items of a
  pool, of any run.
- **Exit codes a job can react to.** `0` completed, `1` failed or completed
  with errors (for a set: stopped at a failure), `2` not over yet (budget
  spent, or waiting for retries or for the source).
- **One run per import at a time.** Starting takes a lock and refuses while a
  run is not over, so two schedules of the same import cannot start two runs.
- **Safe to stop at any moment.** SIGTERM (what Kubernetes sends before it
  kills a pod) finishes the item in hand, gives back the claimed items that
  were not started without costing an attempt, and returns. The next call
  continues from where the run was.
- **Workers are interchangeable.** The queue of items is claimed with a token
  and a lease; a worker that dies loses its claim after the lease and its
  items come back.

## Schedules on imports and run sets

An import or a run set has a schedule of its own: **every so many minutes,
from a time of day**, in whole quarters of an hour and in UTC. Every 60 minutes
from 00:15 is a quarter past every hour; every 1440 minutes from 02:00 is daily
at two. The choices for "every" are 15, 30, 60, 120, 180, 240, 360, 480, 720 and
1440 minutes: the periods that fit a day, so that every day looks the same. It
is set on the last step of the wizard and in the form of a run set, and shows
in their lists.

Nothing in the engine keeps time. **One job, every 15 minutes, asks what is
due**: `drush import:run-due` (the CronJob `import-due` in `deploy/`, or
`*/15 * * * *` in a system cron). It looks at the quarter of an hour it is in
and:

- starts what is due there, run sets first; an import that is in a run set that
  is due is left to the set, so that the order of the set holds;
- **starts nothing twice**: a second call in the same quarter (a cron that ran
  twice, a job that was started again by hand) does nothing;
- **continues a run that is not over** instead of starting another;
- works for as long as `--max-time` allows (840 seconds, so that it is done
  before the next quarter) and gives the exit codes of the other commands: `0`
  completed or nothing due, `1` something failed, `2` something is not over, and
  the next call, or a worker, goes on with it.

A quarter that no call falls in is not made up for: the next one that is due
runs. That is the price of a schedule that is nothing but "what is due now".

## Three ways to start runs

| | Starts a run | Good for |
|---|---|---|
| **Cron** | No: only continues runs that are going, if `cron_resume_seconds` is above 0 (Settings). | Picking up what a stopped run left. |
| **A scheduled drush command** | Yes: `import:run-due` every 15 minutes from the system scheduler, which starts what has a schedule; or `import:run-set catalog` for one fixed set. | One server; the simplest thing that works. |
| **A Kubernetes CronJob per import or set** | Yes. | Separate schedules, resources and failure alerts per import. |

## Kubernetes sketch

One CronJob per run set (or import), and a Deployment of workers per pool. The
CronJob only *starts and drives* a run; heavy item processing can be left to
workers of the pool the import is configured with.

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: import-catalog
spec:
  schedule: "15 * * * *"
  # Never two at once; the engine refuses a second run anyway, this keeps the
  # job history clean.
  concurrencyPolicy: Forbid
  startingDeadlineSeconds: 300
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 5
  jobTemplate:
    spec:
      backoffLimit: 0           # the engine retries; do not retry the job
      activeDeadlineSeconds: 3300
      template:
        spec:
          restartPolicy: Never
          terminationGracePeriodSeconds: 60
          containers:
            - name: drush
              image: registry.example/site-b:<tag>
              command: ["drush", "--uri=https://site-b.example", "import:run-set", "catalog", "--max-time=3000"]
              envFrom:
                - secretRef: {name: site-b-env}   # DB, hash salt, SITE_A_API_KEY
              resources:
                requests: {cpu: 250m, memory: 512Mi}
                limits: {memory: 1Gi}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: import-worker-default
spec:
  replicas: 2
  selector: {matchLabels: {app: import-worker, pool: default}}
  template:
    metadata: {labels: {app: import-worker, pool: default}}
    spec:
      terminationGracePeriodSeconds: 60
      containers:
        - name: drush
          image: registry.example/site-b:<tag>
          command: ["drush", "--uri=https://site-b.example", "import:work", "--pool=default"]
          envFrom:
            - secretRef: {name: site-b-env}
```

Points to decide when this is built:

- **Exit code 2** (not over) is not a failure. Either let the job end and the
  next schedule continue the run (an import with a run that is not over
  continues it), or wrap the command in a loop that calls it again.
- **Memory.** A worker stops when it has used 80% of the PHP memory limit and
  the Deployment restarts it, which is the intended way to deal with growth.
- **Alerts** belong on exit code 1 of the job and on the circuit breaker
  (`drush import:breaker`), not on the number of retries.
- **Secrets** come from the environment, as for the web pods: the database,
  the hash salt and the API key never go in the image or in config.
