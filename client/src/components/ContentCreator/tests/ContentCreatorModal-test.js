/* global jest, test, expect */

import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import '@testing-library/jest-dom';
import axios from 'axios';
import ContentCreatorModal from '../ContentCreatorModal';

// Mock axios
jest.mock('axios');

// Mock window.ss.config
global.window = Object.create(window);
Object.defineProperty(window, 'ss', {
  value: {
    config: {
      SecurityID: 'test-security-id'
    }
  },
  writable: true
});

beforeEach(() => {
  // Clears the mock call history before each test
  axios.get.mockClear();
  axios.post.mockClear();

  // Set up localStorage mock for security token
  Object.defineProperty(window, 'localStorage', {
    value: {
      getItem: jest.fn(() => 'test-security-token'),
      setItem: jest.fn(() => null),
      removeItem: jest.fn(() => null),
    },
    writable: true,
    configurable: true // Allow redefining the property
  });
});

test('ContentCreatorModal renders', async () => {
  // Mock the axios get request for page structure
  axios.get.mockResolvedValueOnce({
    data: {
      success: true,
      structure: [
        {
          name: 'Title',
          title: 'Title',
          type: 'SilverStripe\\Forms\\TextField',
          description: 'The page title'
        },
        {
          name: 'Content',
          title: 'Content',
          type: 'SilverStripe\\Forms\\HTMLEditor\\HTMLEditorField',
          description: 'Main content'
        }
      ]
    }
  });

  // Render the component within act
  await act(async () => {
    render(
      <ContentCreatorModal
        show
        onHide={() => {}}
        pageID="123"
      />
    );
  });

  // Wait for the modal title to appear
  await waitFor(() => {
    expect(screen.getByText('Generate Content with AI')).toBeInTheDocument();
  });

  // Wait for page structure to load
  await waitFor(() => {
    expect(axios.get).toHaveBeenCalledWith(
      '/admin/contentcreator/getPageStructure',
      expect.objectContaining({
        params: { pageID: '123' },
        headers: { 'X-SecurityToken': 'test-security-token' }
      })
    );
  });

  // Check that the page structure is displayed
  expect(screen.getByText('Title')).toBeInTheDocument();
  expect(screen.getByText('Content')).toBeInTheDocument();
});

test('ContentCreatorModal handles form submission', async () => {
  // Mock the axios get request for page structure for this specific test
  axios.get.mockResolvedValueOnce({
    data: {
      success: true,
      structure: [
        {
          name: 'Title',
          title: 'Title',
          type: 'SilverStripe\\Forms\\TextField',
          description: 'The page title'
        }
      ]
    }
  });

  // Mock the axios post request for content generation
  axios.post.mockResolvedValueOnce({
    data: {
      success: true,
      content: {
        Title: 'Generated Title',
        Content: '<p>Generated content</p>'
      }
    }
  });

  let getByText;

  // Render the component within act
  await act(async () => {
    const { getByText: renderedGetByText } = render(
      <ContentCreatorModal
        show
        onHide={() => {}}
        pageID="123"
      />
    );
    getByText = renderedGetByText;
  });

  // Wait for the modal to appear
  await waitFor(() => {
    expect(getByText('Generate Content with AI')).toBeInTheDocument();
  });

  // Ensure getPageStructure was called
  await waitFor(() => {
    expect(axios.get).toHaveBeenCalledWith(
      '/admin/contentcreator/getPageStructure',
      expect.objectContaining({
        params: { pageID: '123' }
      })
    );
  });

  // Simulate form input
  await act(async () => {
    fireEvent.change(screen.getByPlaceholderText("Describe the content you'd like to generate for this page..."), { target: { value: 'Test prompt' } });
  });

  // Simulate form submission
  await act(async () => {
    fireEvent.click(getByText('Generate Content'));
  });

  // Wait for the post request
  await waitFor(() => {
    expect(axios.post).toHaveBeenCalledWith(
      '/admin/contentcreator/generate', // Corrected URL
      expect.objectContaining({
        pageID: '123',
        prompt: 'Test prompt',
      }),
      expect.objectContaining({
        headers: { 'X-SecurityToken': 'test-security-token' }
      })
    );
  });
});
