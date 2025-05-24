/* global jQuery, window */

import React from 'react';
import { createRoot } from 'react-dom/client';
import { ApolloProvider } from '@apollo/client';
import { Provider } from 'react-redux';
import ContentCreatorModal from '../components/ContentCreator/ContentCreatorModal';

// Safely access Apollo client components
const ConfigGraphQLClient = window.ss && window.ss.ConfigGraphQLClient ? window.ss.ConfigGraphQLClient : null;

// Create Apollo client if possible
let apolloClient = null;
if (window.ss && window.ss.apolloClient) {
  apolloClient = window.ss.apolloClient;
} else if (ConfigGraphQLClient && typeof ConfigGraphQLClient.createApolloClient === 'function') {
  apolloClient = ConfigGraphQLClient.createApolloClient();
} else {
  console.warn('Could not find or create Apollo client'); // eslint-disable-line no-console
}

let modalRoot = null; // To store the root for the modal

jQuery.entwine('ss', ($) => {
  /**
   * Initialize Content Creator on the GridField detail form
   */
  $('.cms-edit-form .btn.action_contentcreator').entwine({
    onclick() {
      const recordID = this.data('record-id');
      const modalContainerElement = $('#content-creator-modal')[0];

      if (!modalContainerElement) {
        console.error('Content creator modal container not found'); // eslint-disable-line no-console
        return; // Exit if container not found
      }

      // Check for required store component
      if (!window.ss || !window.ss.store) {
        console.error('SilverStripe Redux store not available.'); // eslint-disable-line no-console
        alert('Required CMS components are not available. Please try refreshing the page.'); // eslint-disable-line no-alert
        return;
      }

      // Create root if it doesn't exist
      if (!modalRoot) {
        modalRoot = createRoot(modalContainerElement);
      }

      const handleClose = () => {
        modalRoot.render(
          <Provider store={window.ss.store}>
            {apolloClient ? (
              <ApolloProvider client={apolloClient}>
                <ContentCreatorModal
                  show={false}
                  onHide={() => {}}
                  pageID={recordID}
                />
              </ApolloProvider>
            ) : (
              <ContentCreatorModal
                show={false}
                onHide={() => {}}
                pageID={recordID}
              />
            )}
          </Provider>
        );
      };

      // Render with or without Apollo provider depending on availability
      modalRoot.render(
        <Provider store={window.ss.store}>
          {apolloClient ? (
            <ApolloProvider client={apolloClient}>
              <ContentCreatorModal
                show
                onHide={handleClose}
                pageID={recordID}
              />
            </ApolloProvider>
          ) : (
            <ContentCreatorModal
              show
              onHide={handleClose}
              pageID={recordID}
            />
          )}
        </Provider>
      );
    }
  });
});
