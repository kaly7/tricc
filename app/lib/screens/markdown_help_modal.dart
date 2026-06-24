import 'package:flutter/material.dart';
import 'package:flutter_markdown/flutter_markdown.dart';

class MarkdownHelpModal {
  static const _items = [
    ('**félkövér**',        '**félkövér**'),
    ('*dőlt*',              '*dőlt*'),
    ('~~áthúzott~~',        '~~áthúzott~~'),
    ('`kód`',               '`kód`'),
    ('# Cím 1',             '# Cím 1'),
    ('## Cím 2',            '## Cím 2'),
    ('### Cím 3',           '### Cím 3'),
    ('- lista elem',        '- lista elem\n- másik elem'),
    ('1. számozott',        '1. első\n2. második'),
    ('> idézet',            '> idézet'),
    ('---',                 '---'),
    ('[szöveg](url)',        '[BabL42](https://rv42.hu)'),
    ('```kód blokk```',     '```\nkód blokk\n```'),
  ];

  static void show(BuildContext context) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Formázási lehetőségek'),
        contentPadding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
        content: SizedBox(
          width: double.maxFinite,
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Ezeket a Markdown jelöléseket használhatod az üzenetekben:',
                  style: TextStyle(fontSize: 13, color: Colors.grey),
                ),
                const SizedBox(height: 12),
                ..._items.map((item) => _HelpRow(syntax: item.$1, markdown: item.$2)),
                const SizedBox(height: 8),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Bezárás'),
          ),
        ],
      ),
    );
  }
}

class _HelpRow extends StatelessWidget {
  final String syntax;
  final String markdown;
  const _HelpRow({required this.syntax, required this.markdown});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 5,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
              decoration: BoxDecoration(
                color: Colors.grey.shade100,
                borderRadius: BorderRadius.circular(4),
                border: Border.all(color: Colors.grey.shade300),
              ),
              child: Text(
                syntax,
                style: const TextStyle(
                  fontFamily: 'monospace',
                  fontSize: 12,
                  color: Colors.black87,
                ),
              ),
            ),
          ),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 6, vertical: 4),
            child: Icon(Icons.arrow_forward, size: 13, color: Colors.grey),
          ),
          Expanded(
            flex: 5,
            child: MarkdownBody(
              data: markdown,
              styleSheet: MarkdownStyleSheet.fromTheme(Theme.of(context)).copyWith(
                p: const TextStyle(fontSize: 13),
                h1: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                h2: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                h3: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                blockquoteDecoration: BoxDecoration(
                  border: Border(left: BorderSide(color: Colors.grey.shade400, width: 3)),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
