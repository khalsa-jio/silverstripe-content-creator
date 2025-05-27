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
            {key}: {value}
          </Badge>
        ))}
      </div>
    </div>
  );
};

/**
 * Component to display field structure in an accordion
 */
const PageStructure = ({ structure }) => {
  const [expanded, setExpanded] = useState(false);
  const [expandedElements, setExpandedElements] = useState({});

  // Count total fields in the structure
  const countFields = () => {
    let totalFields = 0;
    structure.forEach(field => {
      if (field.type === 'ElementalArea' && field.allowedElementTypes) {
        field.allowedElementTypes.forEach(elementType => {
          if (elementType.fields) {
            totalFields += elementType.fields.length;
          }
        });
      } else {
        totalFields += 1;
      }
    });
    return totalFields;
  };

  // Count total element types across all elemental areas
  const countElementTypes = () => {
    let totalElementTypes = 0;
    structure.forEach(field => {
      if (field.type === 'ElementalArea' && field.allowedElementTypes) {
        totalElementTypes += field.allowedElementTypes.length;
      }
    });
    return totalElementTypes;
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
            <i className={`icon ${expanded ? 'font-icon-up-open-big' : 'font-icon-down-open-big'} accordion-icon`} />
          </div>
        </Card.Header>

        {expanded && (
          <Card.Body>
            {/* Regular fields list */}
            {structure.filter(field => field.type !== 'ElementalArea').length > 0 && (
              <div className="regular-fields mb-4">
                <h6>Page Fields</h6>
                <ul className="list-group">
                  {structure.filter(field => field.type !== 'ElementalArea').map((field) => (
                    <li key={field.name} className="list-group-item">
                      <strong>{field.title}</strong> ({field.name})
                      {field.description && <span className="text-muted ml-2"> - {field.description}</span>}
                      {field.options && formatFieldOptions(field.options)}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {/* Elemental Areas with Accordions */}
            {structure.filter(field => field.type === 'ElementalArea').map((field, fieldIndex) => (
              <div key={field.name} className="elemental-area mb-3">
                <h6>{field.title} ({field.name})</h6>
                {field.allowedElementTypes ? (
                  <Accordion className="inner-accordion">
                    {field.allowedElementTypes.map((elementType, elementIndex) => (
                      <Card key={`${field.name}-${elementType.title}`} className="mb-1 border-0">
                        <Card.Header className="bg-light p-1">
                          <Button
                            variant="link"
                            className="text-left w-100 d-flex justify-content-start align-items-center"
                            onClick={() => toggleElementFields(`${fieldIndex}-${elementIndex}`)}
                          >
                            <div className="d-flex align-items-center">
                              <strong>{elementType.title}</strong>
                              <span className="badge badge-primary ml-2">
                                {elementType.fields ? elementType.fields.length : 0} fields
                              </span>
                            </div>
                            <span className="flex-grow-1" />
                            <i className={`icon ${expandedElements[`${fieldIndex}-${elementIndex}`] ? 'font-icon-up-open-big' : 'font-icon-down-open-big'} accordion-icon`} />
                          </Button>
                        </Card.Header>
                        {expandedElements[`${fieldIndex}-${elementIndex}`] && elementType.fields && (
                          <Card.Body className="p-2">
                            <ul className="list-group list-group-flush element-fields-list">
                              {elementType.fields.map((elementField) => (
                                <li
                                  key={`${field.name}-${elementType.title}-${elementField.name}`}
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
                              ))}
                            </ul>
                          </Card.Body>
                        )}
                      </Card>
                    ))}
                  </Accordion>
                ) : (
                  <p className="text-muted">No element types found.</p>
                )}
              </div>
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
