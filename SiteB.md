# Idea

The idea is to make a generic import layer, using self-selecting strategy patterns, like plugins, and configuration entities, so users can add a new import using just the UI. It needs to contain plugins for:
* what type of source
  * HTTP
  * graphql
  * file, optionally on (s)ftp server
  * etc
* what type of target
  * entity
* what method
  * field mapping
    * field map needs to have another plugin structure called field type, so the user can choose what to do to the field. like a formatter. for instance, an image url needs to be downloaded and saved as a File entity

It also needs to be configurable whether to use a circuit breaker, a DLQ, what to do when it fails, etc.


I would also like for the import layer on site B to be a project i can publish on drupal.org
