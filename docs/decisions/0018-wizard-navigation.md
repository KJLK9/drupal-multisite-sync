# 18. Moving through the wizard: a menu of steps, AJAX rows, a list of problems

Date: 2026-10-05 · Status: accepted

## Context
Working with the wizard (ADR 0015) showed three things: adding a row to the mapping reloaded the page and threw the person to the top, going back and forth meant pressing Previous and Next and satisfying every step on the way, and a schema problem was a line in a message without telling where it was.

## Decision
- **A menu of steps at the top**, every step a button. A person can go to any step at any time. Each step that has been visited shows whether it is in order (a tick) or how many problems it has, from the same schema check that Save uses, so what the menu says and what Save says cannot differ.
- **Leaving a step never asks for it to be finished.** Next and Save check the step like before; the menu and Previous take what was typed as it is (unticked boxes count as off, a part that cannot be read keeps the value it had, and the person is told which). Half finished work is not a mistake, and the schema check at Save and the marks in the menu catch what is left.
- **Rows and the source check are AJAX.** Adding or removing a mapping row or a report, and trying the source, replace only their own part of the page; the page stays where it is. The buttons still work without JavaScript, as ordinary submits.
- **The problems are listed in the form, in words**, after a try to save: by step, with where (Mapping, row 2 (field_notes)), which setting (its label from the schema) and why. The list follows the definition as it is, so a problem disappears when it is fixed. They are no longer messages that vanish on the next page.
- **A definition that is not complete can be checked**: the schema is looked up by the ID of the import, so a new import is checked under a placeholder, and a missing name is reported on its own.

## Consequences
- What a browser does with the AJAX buttons and the CSS of the menu is not covered by the Kernel tests; the callbacks, the wrappers and the states are.
- A person can save a definition that was assembled by jumping around; Save refuses it with the list of what is wrong, and opens the first step that has a problem.
