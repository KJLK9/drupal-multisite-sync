# 21. The mapping is a table with a row for every field

Date: 2026-10-05 · Status: accepted · Replaces the mapping rows of ADR 0015

## Context
The mapping step was a list of rows that a person added one at a time: choose a field, choose a mapper, type a path. For a target with a dozen fields that is a dozen times the same three actions, in an order the person had to keep in their head, with nothing that showed which fields were still left.

## Decision
- **The mapping is one table with a row for every field of the target** that a mapper can fill: the field (required ones first, marked), the source or sources, and the mapper with its settings folded away. A field is mapped when it has a source, and left alone when it has none. Adding and removing rows is gone.
- **The mapper is chosen by the type of the field**, in a fixed order (by weight, then by ID), and the sources are the names the mapper declares: an amount and a currency, a first and a second text. Changing the mapper rebuilds the table by AJAX.
- **A person can have the empty fields suggested** (`MappingSuggester`, in the engine): from the paths found in the sample. It compares the words of the name of a field with the words of a path (`customerNumber` and `customer_number` are the same words), counts a few words that mean the same (a label is a name, a description is notes), looks for an amount with a currency next to it as a pair, and only suggests what clearly resembles the field. What is typed or saved is never touched, and the suggestions are said to be suggestions. A reference or a joined text is not guessed: those need to be thought about.
- **The paths of the sample are clickable.** A click puts the path in the source that was last typed in; autocomplete from the same paths stays.
- **The settings of a mapper are only required for a field that is mapped.** The table holds many fields that are left alone, and the required settings of those must not stop the form. They are checked for the fields that have a source.
- **The order of the mapping is kept** when fields come or go, since the order is part of the fingerprint of the mapping (ADR 0008) and a changed order would make every page be read again.
- **A GraphQL source starts its paging with the settings that fit**: the paging values as variables in the body (`variables.offset`, `variables.limit`), and the total next to the list of items. Settings that were saved are not overwritten.

## Consequences
- The table can be long for a target with many fields. The fields no mapper fits (a language) are left out, with a note, and the required ones come first.
- The suggestions are a heuristic. The thresholds are in `MappingSuggester` and have tests with the shapes of the catalog; a source with other names will get fewer suggestions, not wrong ones, since weak resemblances are dropped.
- Clicking a path is JavaScript that no test here runs; its behaviour is small (it remembers a field and fills it) and the markup it depends on is tested.
