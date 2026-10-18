# 29. Gin as the admin theme, with a small subtheme of our own

Date: 2026-10-18 · Status: accepted · Qualifies ADR 0028

## Context
The import screens are administration screens: a wizard, lists, runs. On Claro, the theme of core, they worked but looked plain: grey buttons, a green and blue step bar, a box around every "Settings".

## Decision
- **Gin 5** (`drupal/gin`) is the admin theme of site B, in light mode by default (a user can still choose dark or automatic). It is a subtheme of Claro, so the structure and the CSS of the wizard stay valid, and it works with the navigation of core: `gin_toolbar` is not needed. Gin 6 exists only as a development version and was not taken.
- **A subtheme of our own, `import_admin`** (`web/themes/custom`), is what the screens use. It holds the identity (the accent colour teal, set in its theme settings; the shapes and spacing in `css/admin.css` from Gin's own variables, never fixed colours, so that dark mode keeps working) and the blocks of the theme, which a subtheme has to bring itself. It stays small on purpose: what belongs to a screen of the engine (the mapping table, the help page) is in the CSS of `import_engine_ui`, with the variables of Gin and a fallback, so that the module still looks right on another theme.
- **Site A keeps Claro.** It has no screens of ours.
- Gin repeats the main buttons of a form in a bar that stays in view. The end-to-end tests press those, as a person does.

## Alternatives
- *Claro with all our own CSS*: complete control, but every element (tabs, toggles, dark mode) would be ours to build and keep.
- *Gin without a subtheme*: the accent and the blocks would then be settings of Gin itself, shared with everything else on the site.

## Consequences
- One more Composer package, which goes into the image.
- Gin's own settings are per theme: the accent is set in `import_admin.settings`, not in `gin.settings`.
