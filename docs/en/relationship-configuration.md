# Configuring Relationships in Content Creator

The Content Creator module offers several ways to customize how relationship fields (has_one, has_many, many_many)
are handled during content generation.

## Excluding Relationships

Some relationships shouldn't be included in content generation, such as internal framework classes or complex relationships
that are better managed manually. You can exclude relationships in two ways:

### 1. Excluding by Class

To exclude all relationships to specific classes, add them to `excluded_relationship_classes` in your YAML configuration:

```yaml
KhalsaJio\ContentCreator\Services\ContentGeneratorService:
  excluded_relationship_classes:
    - SilverStripe\SiteConfig\SiteConfig
    - SilverStripe\CMS\Model\SiteTree
    - DNADesign\Elemental\Models\ElementalArea
    - DNADesign\Elemental\Models\BaseElement
    - YourNamespace\YourExcludedClass
```

This will exclude any relation where the target class is or extends one of these classes.

### 2. Excluding Specific Relations

To exclude specific relations while keeping others of the same class, add them to `excluded_specific_relations`:

```yaml
KhalsaJio\ContentCreator\Services\ContentGeneratorService:
  excluded_specific_relations:
    - 'App\Blocks\Cards\CardRowBlock.Cards'
    - 'App\Blocks\Accordion\AccordionBlock.Accordions'
```

The format is `OwnerClass.RelationName`.

## Customizing Related Object Fields

By default, the Content Creator will request all fields for related objects. You can specify which fields
to include for each related class:

```yaml
KhalsaJio\ContentCreator\Services\ContentGeneratorService:
  related_object_fields:
    'App\Models\Product':
      - Title
      - Price
      - Description
    'App\Models\Author':
      - Name
      - Biography
```

## User-friendly Relationship Labels

You can customize how relationships are described in the content generation prompts:

```yaml
KhalsaJio\ContentCreator\Services\ContentGeneratorService:
  relationship_labels:
    has_one: 'Single related item'
    has_many: 'Multiple related items'
    many_many: 'Multiple related items'
    belongs_many_many: 'Multiple related items'
```

## Example Configuration

Here's a complete example configuration combining all the options:

```yaml
---
Name: content-creator-relationships
After:
  - '#content-creator'
---
KhalsaJio\ContentCreator\Services\ContentGeneratorService:
  excluded_relationship_classes:
    - SilverStripe\SiteConfig\SiteConfig
    - SilverStripe\CMS\Model\SiteTree
    - DNADesign\Elemental\Models\ElementalArea
    - DNADesign\Elemental\Models\BaseElement
    
  excluded_specific_relations:
    - 'App\Blocks\Cards\CardRowBlock.Cards'
    - 'App\Blocks\Accordion\AccordionBlock.Accordions'
    
  related_object_fields:
    'App\Models\TestimonialItem':
      - Name
      - Quote
      - Position
    'App\Models\TeamMember': 
      - Name
      - Role
      - Biography
      - Email
      
  relationship_labels:
    has_one: 'Single related item'
    has_many: 'Collection of related items'
    many_many: 'Selected items from collection'
    belongs_many_many: 'Referenced in collection'
```

## Extending via PHP

You can also extend these configurations programmatically:

```php
public function updateExcludedRelationshipClasses(&$excludedClasses)
{
    $excludedClasses[] = MyCustomClass::class;
}

public function updateExcludedSpecificRelations(&$excludedRelations)
{
    $excludedRelations[] = 'MyNamespace\MyClass.MyRelation';
}

public function updateRelatedObjectFields(&$relatedObjectFields, $targetClass)
{
    if ($targetClass === 'MyNamespace\MyClass') {
        $relatedObjectFields[$targetClass] = ['Title', 'Description', 'ImageID'];
    }
}

public function updateRelationshipLabels(&$labels)
{
    $labels['has_many'] = 'Group of related entries';
}
```
