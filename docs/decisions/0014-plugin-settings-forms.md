# 14. Plugins describe their own settings form

Date: 2026-10-04 · Status: accepted

## Context
The interface module needs a form for the settings of every plugin of an import: source, pagination, authentication, target, mappers and reporters. Three ways to get there were weighed: forms in the interface module, one per plugin ID; forms that the plugins describe themselves; or forms generated from the config schema.

## Decision
- **Every plugin type extends Drupal's `PluginFormInterface`**, and a plugin builds, validates and submits its own settings form. The base class of each type uses `PluginFormTrait`, which gives an empty form and turns submitted values into configuration, so a plugin without settings (no pagination, no authentication, the number mapper) needs nothing.
- **A plugin from another module is complete on its own.** A future file source, or a mapper in a site-specific module, shows up in the interface without a line in the interface module. This is the property asked for when the engine was designed: new plugins without changing the structure.
- **Values are cast by the type of the default value** of each setting: a number from a form is a number, a flag is a flag. Lists and maps are text in a form field (`TextLists`): one value per line, or one `name: value` or `name=value` per line. Reading is strict: a line that is not a pair is a form error that names the line and never a value that silently disappears.
- **Secrets stay out.** The authentication form asks for the name of the environment variable that holds the key, never the key; the header fields say where keys do not belong.
- **No dynamic form tricks inside a plugin form.** A form that depends on another field (`#states`, AJAX) would need to know its place in the form that embeds it. The entity target offers type and bundle as one choice (`node:article`) that it splits in two when submitted; fields that only matter for some choices are described as such.
- **The config schema stays the judge.** The forms check what a person can get wrong in a form; the complete definition is validated against its schema before it is saved (the wizard does this at the end). A test builds the form of every plugin from a configuration, submits it unchanged and requires the same configuration back, valid for the schema of that plugin, and a second test fails when a new plugin has no such case.

## Consequences
- The engine now depends on the Form API through its plugin interfaces. That is Drupal's own pattern for configurable plugins and costs a dependency on core only.
- A plugin author who wants a rich form (AJAX, dependent fields) can still override the form methods; what the wizard promises is the plugin form contract and nothing more.
