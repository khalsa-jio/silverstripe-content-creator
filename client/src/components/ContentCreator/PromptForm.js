import React from 'react';
import PropTypes from 'prop-types';
import {
  Form,
  FormGroup,
  FormLabel,
  FormControl,
  FormText,
  Button,
} from 'react-bootstrap';

/**
 * Component for the prompt input form
 */
const PromptForm = ({
  prompt,
  setPrompt,
  onSubmit,
  loading,
  onCancel
}) => {
  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      onSubmit();
    }
  };

  return (
    <div className="prompt-form-container">
      <Form>
        <FormGroup controlId="content-creator-prompt">
          <FormLabel>Enter your content prompt</FormLabel>
          <FormControl
            as="textarea"
            name="prompt"
            rows={4}
            placeholder="Describe the content you'd like to generate for this page..."
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            onKeyDown={handleKeyDown}
            disabled={loading}
          />
          <FormText className="text-muted">
            Describe the content you want to generate for this page. Be specific about tone, style, and key information to include.
          </FormText>
        </FormGroup>
      </Form>

      <div className="d-flex justify-content-end mt-3">
        <Button
          variant="danger"
          onClick={onCancel}
          disabled={loading}
          className="mr-2"
        >
          Cancel
        </Button>
        <Button
          variant="primary"
          onClick={onSubmit}
          disabled={loading || !prompt.trim()}
        >
          {loading ? 'Generating...' : 'Generate Content'}
        </Button>
      </div>
    </div>
  );
};

PromptForm.propTypes = {
  prompt: PropTypes.string.isRequired,
  setPrompt: PropTypes.func.isRequired,
  onSubmit: PropTypes.func.isRequired,
  loading: PropTypes.bool.isRequired,
  onCancel: PropTypes.func.isRequired,
};

export default PromptForm;
