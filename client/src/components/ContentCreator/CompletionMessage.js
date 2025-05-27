import React from 'react';
import PropTypes from 'prop-types';
import { Button, Alert } from 'react-bootstrap';

/**
 * Component to display completion message
 */
const CompletionMessage = ({ onClose }) => (
  <div className="completion-container text-center">
    <Alert variant="success">
      <Alert.Heading>Success!</Alert.Heading>
      <p>Content has been successfully applied to the page!</p>
      <p>The page will reload in a moment to show your new content.</p>
    </Alert>

    <Button
      variant="primary"
      onClick={onClose}
      className="mt-3"
    >
      Close
    </Button>
  </div>
);

CompletionMessage.propTypes = {
  onClose: PropTypes.func.isRequired,
};

export default CompletionMessage;
