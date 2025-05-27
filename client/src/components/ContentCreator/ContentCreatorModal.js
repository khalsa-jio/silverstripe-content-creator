import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import {
  Modal,
  ModalHeader,
  ModalBody,
  ModalTitle,
  Alert,
} from 'react-bootstrap';
import axios from 'axios';
import Analytics from './ContentCreatorAnalytics';

import PageStructure from './PageStructure';
import ChatHistory from './ChatHistory';
import PromptForm from './PromptForm';
import ContentPreview from './ContentPreview';
import CompletionMessage from './CompletionMessage';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Content Creator modal component
 */
const ContentCreatorModal = ({ show, onHide, dataObjectID, dataObjectClass }) => {
  const [loading, setLoading] = useState(false);
  const [prompt, setPrompt] = useState('');
  const [generatedContent, setGeneratedContent] = useState(null);
  const [error, setError] = useState(null);
  const [chatHistory, setChatHistory] = useState([]);
  const [pageStructure, setPageStructure] = useState([]);
  const [step, setStep] = useState('prompt');

  /**
   * Fetch the structure of the page
   */
  const fetchPageStructure = async () => {
    try {
      const response = await axios.get('/admin/contentcreator/getPageStructure', {
        params: { dataObjectID, dataObjectClass },
      });

      if (response.data.success) {
        setPageStructure(response.data.structure);
      } else {
        setError(response.data.error || 'Failed to fetch page structure');
      }
    } catch (err) {
      setError('Failed to fetch page structure. Please try again.');
    }
  };

  useEffect(() => {
    if (show && dataObjectID && dataObjectClass) {
      fetchPageStructure();
    }
    // Reset states when modal is opened
    if (show) {
      setPrompt('');
      setGeneratedContent(null);
      setError(null);
      setStep('prompt');
    }
  }, [show, dataObjectID, dataObjectClass]);

  /**
   * Generate content based on the prompt
   */
  const handleGenerate = async () => {
    if (!prompt.trim()) {
      setError('Please enter a prompt');
      return;
    }

    setLoading(true);
    setError(null);

    // Track generation start
    Analytics.trackGenerationStarted(dataObjectID, dataObjectClass, prompt);
    const startTime = Date.now();

    try {
      const response = await axios.post('/admin/contentcreator/generate', {
        dataObjectID,
        dataObjectClass,
        prompt,
      });

      if (response.data.success) {
        setGeneratedContent(response.data.content);
        setChatHistory([
          ...chatHistory,
          { type: 'prompt', content: prompt },
          { type: 'response', content: response.data.content }
        ]);
        setStep('preview');

        // Track successful generation
        Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, true);
      } else {
        setError(response.data.error || 'Failed to generate content');

        // Track failed generation
        Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, false);
        Analytics.trackError(dataObjectID, dataObjectClass, response.data.error || 'Failed to generate content');
      }
    } catch (err) {
      console.error('Error generating content:', err); // eslint-disable-line no-console
      setError('Failed to generate content. Please try again.');

      // Track error
      Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, false);
      Analytics.trackError(dataObjectID, dataObjectClass, err.message || 'Network error');
    } finally {
      setLoading(false);
    }
  };

  /**
   * Apply the generated content to the page
   */
  const handleApplyContent = async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await axios.post('/admin/contentcreator/applyContent', {
        dataObjectID,
        dataObjectClass,
        content: generatedContent,
      });

      if (response.data.success) {
        setStep('done');

        // Track content application
        Analytics.trackContentApplied(dataObjectID, dataObjectClass);

        // Reload the page to see the new content
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      } else {
        setError(response.data.error || 'Failed to apply content');

        // Track error
        Analytics.trackError(dataObjectID, dataObjectClass, response.data.error || 'Failed to apply content');
      }
    } catch (err) {
      console.error('Error applying content:', err); // eslint-disable-line no-console
      setError('Failed to apply content to the page. Please try again.');

      // Track error
      Analytics.trackError(dataObjectID, dataObjectClass, err.message || 'Network error applying content');
    } finally {
      setLoading(false);
    }
  };

  /**
   * Go back to the prompt step
   */
  const handleBackToPrompt = () => {
    setStep('prompt');
  };

  /**
   * Render the content based on the current step
   */
  const renderStepContent = () => {
    switch (step) {
      case 'prompt':
        return (
          <div className="prompt-step">
            <PromptForm
              prompt={prompt}
              setPrompt={setPrompt}
              onSubmit={handleGenerate}
              loading={loading}
              onCancel={onHide}
            />

            <div className="mt-4">
              <PageStructure structure={pageStructure} />
            </div>

            {chatHistory.length > 0 && (
              <div className="mt-4">
                <ChatHistory history={chatHistory} />
              </div>
            )}
          </div>
        );

      case 'preview':
        return (
          <ContentPreview
            content={generatedContent}
            onApply={handleApplyContent}
            onBack={handleBackToPrompt}
            loading={loading}
          />
        );

      case 'done':
        return (
          <CompletionMessage onClose={onHide} />
        );

      default:
        return null;
    }
  };

  return (
    <Modal
      show={show}
      onHide={onHide}
      size="xl"
      aria-labelledby="content-creator-modal-title"
      centered
      className="content-creator-modal"
    >
      <ModalHeader>
        <ModalTitle id="content-creator-modal-title">
          Generate Content with AI
        </ModalTitle>
      </ModalHeader>

      <ModalBody>
        {error && (
          <Alert variant="danger">{error}</Alert>
        )}

        {renderStepContent()}
      </ModalBody>
    </Modal>
  );
};

ContentCreatorModal.propTypes = {
  show: PropTypes.bool.isRequired,
  onHide: PropTypes.func.isRequired,
  dataObjectID: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  dataObjectClass: PropTypes.string,
};

export default ContentCreatorModal;
