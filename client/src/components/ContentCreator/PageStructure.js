import React, { useState } from 'react';
import PropTypes from 'prop-types';
import {
  Accordion,
  Card,
  Button,
  useAccordionButton,
  Badge,
} from 'react-bootstrap';

/**
 * Custom toggle component for Accordion
 */
function CustomToggle({ children, eventKey, callback }) {
  const [isExpanded, setIsExpanded] = useState(eventKey === '0');

  const decoratedOnClick = useAccordionButton(eventKey, () => {
    setIsExpanded(!isExpanded);
    if (callback) callback(eventKey);
  });

  return (
    <Button
      variant="link"
      className="text-left w-100 d-flex justify-content-start align-items-center"
      onClick={decoratedOnClick}
    >
      <span className="mr-2">{children}</span>
      <span className="flex-grow-1" />
      <i className={`icon ${isExpanded ? 'font-icon-down-open-big' : 'font-icon-up-open-big'} accordion-icon`} />
      <span className="sr-only">{isExpanded ? 'Collapse' : 'Expand'}</span>
    </Button>
  );
}

CustomToggle.propTypes = {
  children: PropTypes.node,
  eventKey: PropTypes.string.isRequired,
  callback: PropTypes.func,
};

/**
 * Helper function to format field options for display
 */
const formatFieldOptions = (options) => {
  if (!options) return null;

  // Check if this is a tree selection
  if (options.type === 'TreeSelection') {
    return (
      <span className="text-info">
        Tree selection from {options.class}
      </span>
    );
  }

  return (
    <div className="field-options mt-1">
      <small className="d-block">Options:</small>
      <div className="d-flex flex-wrap">
        {Object.entries(options).map(([key, value]) => (
          <Badge
            key={key}
            variant="light"
            className="mr-1 mb-1 border"
          >
            {value}
          </Badge>
        ))}
      </div>
    </div>
  );
};

/**
 * Recursive component to render ElementalArea fields and their nested elements
 */
const ElementalAreaRenderer = ({ field, fieldIndex, level = 0, onToggle, expandedElements, maxDepth = 5 }) => {
  // Check if we've reached the maximum depth
  const reachedMaxDepth = level >= maxDepth;

  const renderField = (elementField, parentPath = '') => {
    const fieldPath = parentPath ? `${parentPath}-${elementField.name}` : elementField.name;

    if (elementField.type === 'ElementalArea') {
      // If we've reached maximum depth, just show a placeholder
      if (reachedMaxDepth) {
        return (
          <div key={fieldPath} className="nested-elemental-area ml-3 pl-2 border-left">
            <div className="max-depth-warning">
              <span className="text-warning">
                <i className="icon font-icon-attention mr-1" />
                Maximum nesting depth reached
              </span>
            </div>
          </div>
        );
      }

      // Render nested ElementalArea recursively
      return (
        <div key={fieldPath} className="nested-elemental-area ml-3 pl-2 border-left">
          <ElementalAreaRenderer
            field={elementField}
            fieldIndex={`${fieldPath}`}
            level={level + 1}
            onToggle={onToggle}
            expandedElements={expandedElements}
            maxDepth={maxDepth}
          />
        </div>
      );
    } else {
      // Render regular field
      return (
        <li
          key={fieldPath}
          className="list-group-item py-0 px-1 border-0 element-field-item"
        >
          <span><strong>{elementField.title}</strong></span>
          {elementField.description && (
            <small className="text-muted d-block">
              {elementField.description}
            </small>
          )}
          {elementField.options && formatFieldOptions(elementField.options)}
        </li>
      );
    }
  };

  return (
    <div className={`elemental-area mb-3 ${level > 0 ? 'nested-area' : ''}`}>
      <h6 className={`${level > 0 ? 'h6 font-weight-bold' : ''}`}>
        {field.title} ({field.name})
        {level > 0 && <Badge variant="info" className="ml-2 text-white">Nested Area</Badge>}
      </h6>
      {field.allowedElementTypes ? (
        <Accordion className="inner-accordion">
          {field.allowedElementTypes.map((elementType, elementIndex) => {
            const elementKey = `${fieldIndex}-${elementIndex}`;
            return (
              <Card key={`${field.name}-${elementType.title}`} className="mb-1 border-0">
                <Card.Header className="bg-light p-1">
                  <Button
                    variant="link"
                    className="text-left w-100 d-flex justify-content-start align-items-center"
                    onClick={() => onToggle(elementKey)}
                  >
                    <div className="d-flex align-items-center">
                      <strong>{elementType.title}</strong>
                      <span className="badge badge-primary ml-2">
                        {elementType.fields ? elementType.fields.length : 0} fields
                      </span>
                    </div>
                    <span className="flex-grow-1" />
                    <i className={`icon ${expandedElements[elementKey] ? 'font-icon-up-open-big' : 'font-icon-down-open-big'} accordion-icon`} />
                  </Button>
                </Card.Header>
                {expandedElements[elementKey] && (
                  <Card.Body className="p-2">
                    {elementType.fields && elementType.fields.length > 0 ? (
                      <ul className="list-group list-group-flush element-fields-list">
                        {elementType.fields.map((elementField) => renderField(elementField, `${field.name}-${elementType.title}`))}
                      </ul>
                    ) : (
                      <div className="alert alert-info">
                        <small>No fields found for this element type. If you expect fields here, check ElementalArea relationship handling in ContentStructureService.</small>
                      </div>
                    )}
                  </Card.Body>
                )}
              </Card>
            );
          })}
        </Accordion>
      ) : (
        <p className="text-muted">No element types found.</p>
      )}
    </div>
  );
};

/**
 * Component to display field structure in an accordion
 */
const PageStructure = ({ structure }) => {
  const [expanded, setExpanded] = useState(false);
  const [expandedElements, setExpandedElements] = useState({});

  // Count total fields in the structure, including nested fields
  const countFields = () => {
    const countFieldsRecursive = (fields) => {
      if (!fields) return 0;

      let totalFields = 0;

      fields.forEach((field) => {
        if (field.type === 'ElementalArea' && field.allowedElementTypes) {
          // Count ElementalArea as a field
          totalFields += 1;

          // Count fields in each element type
          field.allowedElementTypes.forEach((elementType) => {
            if (elementType.fields) {
              totalFields += countFieldsRecursive(elementType.fields);
            }
          });
        } else {
          totalFields += 1;
        }
      });

      return totalFields;
    };

    return countFieldsRecursive(structure);
  };

  // Count total element types across all elemental areas
  const countElementTypes = () => {
    const uniqueElementTypes = new Set();

    const collectUniqueElementTypes = (fields) => {
      if (!fields) return;

      fields.forEach((field) => {
        // Collect unique element types
        if (field.type === 'ElementalArea' && field.allowedElementTypes) {
          field.allowedElementTypes.forEach((elementType) => {
            uniqueElementTypes.add(elementType.class);

            // Also check for nested ElementalAreas within this element type
            if (elementType.fields) {
              const nestedFields = elementType.fields.filter((f) => f.type === 'ElementalArea');
              if (nestedFields.length > 0) {
                collectUniqueElementTypes(nestedFields);
              }
            }
          });
        }
      });
    };

    // Collect all unique element types recursively
    collectUniqueElementTypes(structure);

    // Return the count of unique element types
    return uniqueElementTypes.size;
  };

  const toggleElementFields = (elementKey) => {
    setExpandedElements(prev => ({
      ...prev,
      [elementKey]: !prev[elementKey]
    }));
  };

  if (!structure || structure.length === 0) {
    return <p>Loading page structure...</p>;
  }

  return (
    <div className="content-creator-page-structure">
      <Card className="mb-3 border-0">
        <Card.Header className="bg-light">
          <div
            className="d-flex justify-content-between align-items-center cursor-pointer"
            onClick={() => setExpanded(!expanded)}
            style={{ cursor: 'pointer' }}
          >
            <div>
              <h5 className="mb-0">Page Structure</h5>
              <div className="d-flex mt-1">
                <span className="badge badge-info mr-2">{structure.length} sections</span>
                <span className="badge badge-primary mr-2">{countFields()} fields</span>
                <span className="badge badge-secondary">{countElementTypes()} element types</span>
              </div>
            </div>
            <i
              className={`icon ${expanded ? 'font-icon-up-open-big' : 'font-icon-down-open-big'} accordion-icon`}
            />
          </div>
        </Card.Header>

        {expanded && (
          <Card.Body>
            {/* Regular fields list */}
            {structure.filter((field) => field.type !== 'ElementalArea').length > 0 && (
              <div className="regular-fields mb-4">
                <h6>Page Fields</h6>
                <ul className="list-group">
                  {structure
                    .filter((field) => field.type !== 'ElementalArea')
                    .map((field) => (
                      <li key={field.name} className="list-group-item">
                        <strong>{field.title}</strong> ({field.name})
                        {field.description && (
                          <span className="text-muted ml-2"> - {field.description}</span>
                        )}
                        {field.options && formatFieldOptions(field.options)}
                      </li>
                    ))}
                </ul>
              </div>
            )}

            {/* Elemental Areas with Accordions - using recursive renderer */}
            {structure
              .filter((field) => field.type === 'ElementalArea')
              .map((field, fieldIndex) => (
                <ElementalAreaRenderer
                  key={field.name}
                  field={field}
                  fieldIndex={fieldIndex}
                  onToggle={toggleElementFields}
                  expandedElements={expandedElements}
                  maxDepth={5} // Using 5 as default to match the backend configuration
                />
              ))}
          </Card.Body>
        )}
      </Card>
    </div>
  );
};

PageStructure.propTypes = {
  structure: PropTypes.array,
};

export default PageStructure;
