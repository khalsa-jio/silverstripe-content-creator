# Configuration Options

This document outlines the various configuration options available in the SilverStripe Content Creator module.

## Page Structure Display

You can control whether the page structure is displayed in the content creator modal using YAML configuration:

```yaml
---
Name: my-content-creator-config
After: '#content-creator-config'
---
KhalsaJio\ContentCreator\Services\ContentStructureService:
  show_page_structure: false # Set to false to hide the page structure in the modal
```

By default, the page structure is shown in the modal (`show_page_structure: true`). Setting this to `false` will hide the page structure section, allowing for a cleaner interface when users don't need to see the underlying structure of the page.

## Other Configuration Options

The Content Creator module provides several other configuration options:

### Field Exclusions

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  excluded_field_names:
    - 'ID'
    - 'Created' 
    - 'LastEdited'
    # Add your custom fields to exclude here
```

### Relationship Inclusions

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  included_relationship_classes:
    - 'My\App\RelationshipClass'
```

### Specific Relations

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  included_specific_relations:
    - 'My\App\Class.RelationName'
```
