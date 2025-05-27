import React from 'react';
import DOMPurify from 'dompurify';
import PropTypes from 'prop-types';
import { Button, Card } from 'react-bootstrap';

/**
 * Component to preview generated content
 */
const ContentPreview = ({
  content,
  onApply,
  onBack,
  loading
}) => {
  if (!content) {
    return <div className="alert alert-warning">No content available for preview</div>;
  }

  const renderContentSection = (key, value, depth = 0) => {
    const padding = depth * 10;

    if (typeof value === 'object' && value !== null) {
      return (
        <div key={key} style={{ paddingLeft: `${padding}px` }} className="mb-3">
          <h6 className="font-weight-bold">{key}</h6>
          <div className="card p-2 mb-2">
            {Object.entries(value).map(([nestedKey, nestedValue]) =>
              renderContentSection(nestedKey, nestedValue, depth + 1)
            )}
          </div>
        </div>
      );
    }

    return (
      <div key={key} style={{ paddingLeft: `${padding}px` }} className="mb-2">
        <div className="d-flex">
          <strong className="mr-2">{key}:</strong>
          <div className="content-value">
            {typeof value === 'string' && value.includes('<') && value.includes('>') ? (
              <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(value) }} /> // eslint-disable-line react/no-danger
            ) : (
              <span>{value}</span>
            )}
          </div>
        </div>
      </div>
    );
  };

  return (
    <div className="content-preview-container">
      <h5>Generated Content Preview</h5>
      <Card className="preview-card mb-3">
        <Card.Body>
          <div className="content-preview">
            {Object.entries(content).map(([key, value]) => renderContentSection(key, value))}
          </div>
        </Card.Body>
      </Card>

      <div className="d-flex justify-content-between mt-3">
        <Button
          variant="secondary"
          onClick={onBack}
          disabled={loading}
        >
          Back
        </Button>
        <Button
          variant="success"
          onClick={onApply}
          disabled={loading}
        >
          {loading ? 'Applying...' : 'Apply Content to Page'}
        </Button>
      </div>
    </div>
  );
};

ContentPreview.propTypes = {
  content: PropTypes.object,
  onApply: PropTypes.func.isRequired,
  onBack: PropTypes.func.isRequired,
  loading: PropTypes.bool.isRequired,
};

export default ContentPreview;
