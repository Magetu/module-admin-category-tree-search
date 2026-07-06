# Magetu_AdminCategoryTreeSearch

The Magento 2 and Adobe Commerce category tree search filter. Type a category **name or ID** and
the tree filters live: matches are shown and highlighted, their ancestor paths auto-expand,
everything else hides. A × clears the search and restores the tree exactly as the admin had it
arranged.

## Why

Neither Magento Open Source nor Adobe Commerce ships a way to search the admin category tree —
finding a category in a large catalog means manually expanding and scrolling.

## How it works

The admin category tree (`Magento\Catalog\Block\Adminhtml\Category\Tree`) sets `useAjax(0)`,
so the entire tree ships inline into jstree's in-browser model on page load. This module adds a
search box that filters that existing jstree instance client-side, using jstree's own
`hide_all` / `show_node` / `open_node` — no core template override, no controller, no server
round trip. It only depends on `magento/module-catalog`, `magento/module-backend`, and
`magento/framework`, so it installs on Magento Open Source, Adobe Commerce, and Mage-OS alike.

## Install

```bash
composer require magetu/module-admin-category-tree-search
bin/magento setup:upgrade
```

## Large-catalog behavior

- Category name lookup is a single cached SQL query (no model hydration), invalidated on
  category save.
- Search only activates at 2+ characters (a single character is too broad to be useful),
  except an exact category ID match.
- Revealed matches are capped (default 500) with a "keep typing to narrow down" notice, so a
  broad query on a very large catalog can never force the tree to open thousands of subtrees
  at once.

## Tests

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php Test/Unit
```

## Requirements

Built for Adobe Commerce / Magento Open Source **2.4.x** and **Mage-OS**, **PHP 8.1 – 8.5**.

The unit suite (`Test/Unit/`) runs on **PHPUnit 9.5, 10.5, or 12** — it uses no APIs removed in
PHPUnit 10/12. Note PHPUnit 12 itself requires PHP 8.3+.

## License

MIT
