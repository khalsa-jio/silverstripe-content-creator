/* global jQuery, window */

import React from 'react';
import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import ContentCreatorModal from '../components/ContentCreator/ContentCreatorModal';

let modalRoot = null; // To store the root for the modal

jQuery.entwine('ss', ($) => {
  /**
   * Initialize Content Creator on the GridField detail form
   */
  $('.cms-edit-form .btn.action_contentcreator').entwine({
    onmatch() {
      this._super();
      this.prop('disabled', true).addClass('disabled');

      const checkDependencies = () => {
        const storeIsReady = !!(window.ss && window.ss.store);

        if (storeIsReady) {
          this.prop('disabled', false).removeClass('disabled');
          if (this.data('dependencyCheckInterval')) {
            clearInterval(this.data('dependencyCheckInterval'));
            this.removeData('dependencyCheckInterval');
          }
        } else if (!this.data('dependencyCheckInterval')) {
          const intervalId = setInterval(checkDependencies, 500);
          this.data('dependencyCheckInterval', intervalId);
        }
      };

      checkDependencies();
    },
    onunmatch() {
      this._super();

      if (this.data('dependencyCheckInterval')) {
        clearInterval(this.data('dependencyCheckInterval'));
      }
    },
    onclick(e) {
      if (this.prop('disabled')) {
        e.preventDefault();
        return;
      }
      e.preventDefault();

      const recordID = this.data('record-id');
      const recordClass = this.data('record-class'); // Get the DataObject class
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
            <ContentCreatorModal
              show={false}
              onHide={() => {}}
              dataObjectID={recordID}
              dataObjectClass={recordClass}
            />
          </Provider>
        );
      };

      // Render with or without Apollo provider depending on availability
      modalRoot.render(
        <Provider store={window.ss.store}>
          <ContentCreatorModal
            show
            onHide={handleClose}
            dataObjectID={recordID}
            dataObjectClass={recordClass}
          />
        </Provider>
      );
    }
  });
});
