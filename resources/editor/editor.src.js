// CodeMirror 6 wrapper bundled (esbuild) into resources/dist/editor.js and served locally.
// Exposes one global so app.js never touches CM6 internals.
import { EditorView, basicSetup } from 'codemirror';
import { EditorState, Prec } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { php } from '@codemirror/lang-php';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags as t } from '@lezer/highlight';

// Chrome theme — uses the app's :root CSS vars so it stays in sync with the surrounding UI.
const appTheme = EditorView.theme(
  {
    '&': { color: 'var(--text)', backgroundColor: 'var(--bg)', height: '100%', fontSize: '13px' },
    '.cm-content': { fontFamily: 'var(--mono)', caretColor: 'var(--accent)', padding: '12px 0' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--accent)' },
    '&.cm-focused': { outline: 'none' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground, .cm-content ::selection': {
      backgroundColor: 'rgba(108,199,255,0.25)',
    },
    '.cm-gutters': { backgroundColor: 'var(--bg)', color: 'var(--muted)', border: 'none' },
    '.cm-activeLine': { backgroundColor: 'rgba(255,255,255,0.03)' },
    '.cm-activeLineGutter': { backgroundColor: 'rgba(255,255,255,0.04)', color: 'var(--text)' },
    '.cm-matchingBracket, &.cm-focused .cm-matchingBracket': {
      backgroundColor: 'rgba(108,199,255,0.2)', outline: 'none',
    },
    '.cm-scroller': { overflow: 'auto', fontFamily: 'var(--mono)' },
  },
  { dark: true }
);

// Syntax token colors legible on the dark background.
const appHighlight = HighlightStyle.define([
  { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.operatorKeyword], color: 'var(--accent)' },
  { tag: [t.function(t.variableName), t.function(t.propertyName)], color: 'var(--accent)' },
  { tag: [t.string, t.special(t.string)], color: 'var(--ok)' },
  { tag: [t.number, t.bool, t.null], color: '#79c0ff' },
  { tag: [t.comment, t.lineComment, t.blockComment], color: 'var(--muted)', fontStyle: 'italic' },
  { tag: [t.variableName, t.propertyName], color: 'var(--text)' },
  { tag: [t.className, t.typeName, t.namespace], color: '#e3b341' },
  { tag: [t.operator, t.punctuation, t.bracket, t.separator], color: 'var(--muted)' },
]);

window.TinkerEditor = {
  create(parent, { doc = '', onChange, onRun } = {}) {
    // High precedence so Mod-Enter beats basicSetup's default binding.
    const runKey = Prec.highest(
      keymap.of([{ key: 'Mod-Enter', preventDefault: true, run: () => { if (onRun) onRun(); return true; } }])
    );
    const view = new EditorView({
      parent,
      state: EditorState.create({
        doc,
        extensions: [
          runKey,
          basicSetup,
          keymap.of([indentWithTab]),
          php(),
          appTheme,
          syntaxHighlighting(appHighlight),
          EditorView.updateListener.of((u) => { if (u.docChanged && onChange) onChange(); }),
        ],
      }),
    });
    return {
      getDoc: () => view.state.doc.toString(),
      setDoc: (text) => view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } }),
      focus: () => view.focus(),
    };
  },
};
