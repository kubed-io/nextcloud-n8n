/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests for the pure Files-integration helpers. These are the JS analog of
 * the PHP FilenameCodec tests: dependency-free logic, fast, and the regression
 * net that makes a Vite major bump safe to land.
 */
import { describe, it, expect } from 'vitest'
import { N8N_MIME, getN8nId, buildUrl, isN8nFile, getN8nMode, canOpenInN8n, canEditAsText, defaultOpener, toggleTargetTag } from '../../src/files-helpers.js'

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

describe('getN8nMode', () => {
  it('reads the plain metadata-n8n_mode attribute', () => {
    expect(getN8nMode({ attributes: { 'metadata-n8n_mode': 'sync' } })).toBe('sync')
  })

  it('translates the wire value "reference" back to "link"', () => {
    expect(getN8nMode({ attributes: { 'metadata-n8n_mode': 'reference' } })).toBe('link')
  })

  it('falls back to the bare n8n_mode attribute', () => {
    expect(getN8nMode({ attributes: { n8n_mode: 'unmapped' } })).toBe('unmapped')
  })

  it('falls back to the fully-qualified DAV attribute name', () => {
    expect(getN8nMode({ attributes: { '{http://nextcloud.org/ns}metadata-n8n_mode': 'ignored' } })).toBe('ignored')
  })

  it('returns empty string when absent (first-load race / untracked file)', () => {
    expect(getN8nMode({ attributes: {} })).toBe('')
    expect(getN8nMode()).toBe('')
    expect(getN8nMode(null)).toBe('')
  })

  it('ignores a non-string mode value', () => {
    expect(getN8nMode({ attributes: { 'metadata-n8n_mode': 42 } })).toBe('')
  })
})

describe('canOpenInN8n', () => {
  it('offers "Open in n8n" for sync and link (a live workflow exists)', () => {
    expect(canOpenInN8n('sync')).toBe(true)
    expect(canOpenInN8n('link')).toBe(true)
  })

  it('hides "Open in n8n" for unmapped and ignored (no live workflow)', () => {
    expect(canOpenInN8n('unmapped')).toBe(false)
    expect(canOpenInN8n('ignored')).toBe(false)
  })

  it('stays permissive for an absent/unknown mode (first-load race)', () => {
    expect(canOpenInN8n('')).toBe(true)
  })
})

describe('canEditAsText', () => {
  it('offers the text editor for every mode that holds the full JSON', () => {
    expect(canEditAsText('sync')).toBe(true)
    expect(canEditAsText('unmapped')).toBe(true)
    expect(canEditAsText('ignored')).toBe(true)
  })

  it('hides the text editor for link (a pointer — nothing to edit)', () => {
    expect(canEditAsText('link')).toBe(false)
  })

  it('stays permissive for an absent/unknown mode (first-load race)', () => {
    expect(canEditAsText('')).toBe(true)
  })
})

describe('defaultOpener', () => {
  it('defaults sync/link to n8n', () => {
    expect(defaultOpener('sync')).toBe('n8n')
    expect(defaultOpener('link')).toBe('n8n')
  })

  it('defaults unmapped/ignored to the text editor', () => {
    expect(defaultOpener('unmapped')).toBe('text')
    expect(defaultOpener('ignored')).toBe('text')
  })

  it('defaults an absent mode to n8n (matches canOpenInN8n)', () => {
    expect(defaultOpener('')).toBe('n8n')
  })
})

describe('toggleTargetTag', () => {
  it('flips sync → n8n:link', () => {
    expect(toggleTargetTag('sync')).toBe('n8n:link')
  })

  it('flips link → n8n:sync', () => {
    expect(toggleTargetTag('link')).toBe('n8n:sync')
  })

  it('returns "" for non-toggleable modes (unmapped/ignored/absent)', () => {
    expect(toggleTargetTag('unmapped')).toBe('')
    expect(toggleTargetTag('ignored')).toBe('')
    expect(toggleTargetTag('')).toBe('')
  })
})
