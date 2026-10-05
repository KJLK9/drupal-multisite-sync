# Scheduling imports

Status: **a design that was not run.** The engine is built so that this works
without further code; nothing below has been tried on a cluster. It is the
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

## Three ways to start runs

| | Starts a run | Good for |
|---|---|---|
| **Cron** | No: only continues runs that are going, if `cron_resume_seconds` is above 0 (Settings). | Picking up what a stopped run left. |
| **A scheduled drush command** | Yes: `import:run-set catalog` from the system scheduler. | One server; the simplest thing that works. |
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
- **A schedule on the definition** (a cron expression stored with the import,
  and something that creates the CronJobs from it) is the one thing that would
  still need to be built.
- **Secrets** come from the environment, as for the web pods: the database,
  the hash salt and the API key never go in the image or in config.
