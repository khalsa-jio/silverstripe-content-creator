/* global jest, test, expect */

import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import '@testing-library/jest-dom';
import axios from 'axios';
import ContentCreatorModal from '../ContentCreatorModal';

// Mock axios
jest.mock('axios');

beforeEach(() => {
  // Clears the mock call history before each test
  axios.get.mockClear();
  axios.post.mockClear();

  // Configure axios defaults for tests to match the implementation
  axios.defaults.headers = {
    common: {
      'X-Requested-With': 'XMLHttpRequest',
    },
  };
});

test('ContentCreatorModal renders', async () => {
  let resolveAxiosGet;
  const axiosGetPromise = new Promise((resolve) => {
    resolveAxiosGet = resolve;
  });

  // Mock the axios get request for page structure
  axios.get.mockImplementationOnce(() =>
    axiosGetPromise.then(() => ({
      data: {
        success: true,
        structure: [
          {
            name: 'Title',
            title: 'Title',
            type: 'SilverStripe\\Forms\\TextField',
            description: 'The page title',
          },
          {
            name: 'Content',
            title: 'Content',
            type: 'SilverStripe\\Forms\\HTMLEditor\\HTMLEditorField',
            description: 'Main content',
          },
        ],
      },
    })),
  );

  // Render the component within act
  await act(async () => {
    render(<ContentCreatorModal show onHide={() => {}} dataObjectID="123" dataObjectClass="Page" />);
  });

  // Wait for the modal title to appear
  await waitFor(() => {
    expect(screen.getByText('Generate Content with AI')).toBeInTheDocument();
  });

  // Verify the API was called correctly
  await waitFor(() => {
    expect(axios.get).toHaveBeenCalledWith(
      '/admin/contentcreator/getPageStructure',
      expect.objectContaining({
        params: {
          dataObjectID: '123',
          dataObjectClass: 'Page',
        },
      }),
    );
  });

  // Resolve the API request inside act to properly handle state updates
  await act(async () => {
    resolveAxiosGet();
    // Wait longer for React to process all state updates
    await new Promise((resolve) => setTimeout(resolve, 100));
  });

  // Check that the page structure container is displayed with the correct heading
  await waitFor(
    () => {
      expect(screen.getByText('Page Structure')).toBeInTheDocument();
    },
    { timeout: 3000 },
  );

  // Verify the structure information based on what we know will show initially
  await waitFor(
    () => {
      // Test for badge showing section count
      expect(screen.getByText(/sections/i)).toBeInTheDocument();
      // Test for badge showing field count
      expect(screen.getByText(/fields/i)).toBeInTheDocument();
    },
    { timeout: 3000 },
  );
});

test('ContentCreatorModal handles form submission', async () => {
  // Create promises to control when axios mocks resolve
  let resolveAxiosGet;
  let resolveAxiosPost;

  const axiosGetPromise = new Promise((resolve) => {
    resolveAxiosGet = resolve;
  });
  const axiosPostPromise = new Promise((resolve) => {
    resolveAxiosPost = resolve;
  });

  // Mock the axios get request for page structure for this specific test
  axios.get.mockImplementationOnce(() =>
    axiosGetPromise.then(() => ({
      data: {
        success: true,
        structure: [
          {
            name: 'Title',
            title: 'Title',
            type: 'SilverStripe\\Forms\\TextField',
            description: 'The page title',
          },
        ],
      },
    })),
  );

  // Mock the axios post request for content generation
  axios.post.mockImplementationOnce(() =>
    axiosPostPromise.then(() => ({
      data: {
        success: true,
        content: {
          Title: 'Generated Title',
          Content: '<p>Generated content</p>',
        },
      },
    })),
  );

  let getByText;

  // Render the component within act
  await act(async () => {
    const { getByText: renderedGetByText } = render(
      <ContentCreatorModal show onHide={() => {}} dataObjectID="123" dataObjectClass="Page" />,
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
        params: {
          dataObjectID: '123',
          dataObjectClass: 'Page',
        },
      }),
    );
  });

  // Resolve the get request inside act
  await act(async () => {
    resolveAxiosGet();
  });

  // Simulate form input
  await act(async () => {
    fireEvent.change(
      screen.getByPlaceholderText("Describe the content you'd like to generate for this page..."),
      {
        target: { value: 'Test prompt' },
      },
    );
  });

  // Simulate form submission
  await act(async () => {
    fireEvent.click(getByText('Generate Content'));
  });

  // Wait for the post request
  await waitFor(() => {
    expect(axios.post).toHaveBeenCalledWith(
      '/admin/contentcreator/generate',
      expect.objectContaining({
        dataObjectID: '123',
        dataObjectClass: 'Page',
        prompt: 'Test prompt',
      }),
    );
  });

  // Resolve the post request inside act
  await act(async () => {
    resolveAxiosPost();
    // Wait longer for React to process all state updates
    await new Promise((resolve) => setTimeout(resolve, 100));
  });

  // Verify the component has moved to the preview step
  await waitFor(
    () => {
      // Look for the "Generated Content Preview" heading that appears in the ContentPreview component
      expect(screen.queryByText('Generated Content Preview')).toBeInTheDocument();
    },
    { timeout: 3000 },
  );
});
