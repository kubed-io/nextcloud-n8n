/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests for the pure Files-integration helpers. These are the JS analog of
 * the PHP FilenameCodec tests: dependency-free logic, fast, and the regression
 * net that makes a Vite major bump safe to land.
 */
import { describe, it, expect } from 'vitest'
import { N8N_MIME, getN8nId, buildUrl, isN8nFile } from '../../src/files-helpers.js'

describe('getN8nId', () => {
  it('reads the plain metadata-n8n_id attribute', () => {
    expect(getN8nId({ attributes: { 'metadata-n8n_id': 'w0TtomB3I8dCHSXW' } })).toBe('w0TtomB3I8dCHSXW')
  })

  it('falls back to the bare n8n_id attribute', () => {
    expect(getN8nId({ attributes: { n8n_id: 'abc123' } })).toBe('abc123')
  })

  it('falls back to the fully-qualified DAV attribute name', () => {
    expect(getN8nId({ attributes: { '{http://nextcloud.org/ns}metadata-n8n_id': 'xyz789' } })).toBe('xyz789')
  })

  it('returns empty string when the attribute is absent', () => {
    expect(getN8nId({ attributes: {} })).toBe('')
  })

  it('is null/undefined safe (no node, no attributes)', () => {
    expect(getN8nId()).toBe('')
    expect(getN8nId(null)).toBe('')
    expect(getN8nId({})).toBe('')
  })

  it('ignores a non-string id value', () => {
    expect(getN8nId({ attributes: { 'metadata-n8n_id': 12345 } })).toBe('')
  })
})

describe('buildUrl', () => {
  it('builds a workflow deep link from base url + id', () => {
    expect(buildUrl('https://n8n.example.com', 'w0Ttom')).toBe('https://n8n.example.com/workflow/w0Ttom')
  })

  it('url-encodes the id', () => {
    expect(buildUrl('https://n8n.example.com', 'a b/c')).toBe('https://n8n.example.com/workflow/a%20b%2Fc')
  })

  it('returns empty string when the base url is missing', () => {
    expect(buildUrl('', 'w0Ttom')).toBe('')
  })

  it('returns empty string when the id is missing', () => {
    expect(buildUrl('https://n8n.example.com', '')).toBe('')
  })
})

describe('isN8nFile', () => {
  it('matches a single node with the n8n mimetype', () => {
    expect(isN8nFile({ nodes: [{ mime: N8N_MIME }] })).toBe(true)
  })

  it('matches a single node by .n8n.json basename', () => {
    expect(isN8nFile({ nodes: [{ basename: 'Daily Report.n8n.json' }] })).toBe(true)
  })

  it('does not match plain JSON', () => {
    expect(isN8nFile({ nodes: [{ mime: 'application/json', basename: 'notes.json' }] })).toBe(false)
  })

  it('does not match a multi-node selection', () => {
    expect(isN8nFile({ nodes: [{ mime: N8N_MIME }, { mime: N8N_MIME }] })).toBe(false)
  })

  it('is empty/garbage safe', () => {
    expect(isN8nFile()).toBe(false)
    expect(isN8nFile({ nodes: [] })).toBe(false)
    expect(isN8nFile({})).toBe(false)
  })
})
