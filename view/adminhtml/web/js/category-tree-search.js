/**
 * Real-time search filter for the admin category tree.
 *
 * The category management tree is rendered by core with useAjax=0, so the whole tree is shipped
 * inline and lives in jstree's model on load. Filtering therefore needs no server call: we match a
 * client-side {id: name} map against the query, then drive jstree's own hide/show/open so only the
 * matches and their ancestor paths remain visible. Clearing restores the default (collapsed) view.
 *
 * Large-catalog guards: names are lowercased once at init (not per keystroke), queries shorter
 * than MIN_QUERY only match an exact id (a single character matches far too broadly to be useful),
 * and the revealed set is capped at config.maxResults so a broad query can never force jstree to
 * open thousands of subtrees at once.
 */
define(['jquery', 'jquery/jstree/jquery.jstree'], function ($) {
    'use strict';

    var DEBOUNCE_MS = 200,
        MIN_QUERY = 2;

    /**
     * @param {Object} config
     * @param {String} config.treeSelector - selector for the jstree container (default '#tree-div')
     * @param {Object.<String,String>} config.names - map of category id => name
     * @param {Number} config.maxResults - cap on how many matches are revealed at once
     * @param {HTMLElement} element - the search-box wrapper
     */
    return function (config, element) {
        var $wrap = $(element),
            $input = $wrap.find('[data-role="cts-input"]'),
            $clear = $wrap.find('[data-role="cts-clear"]'),
            $empty = $wrap.find('[data-role="cts-empty"]'),
            $capped = $wrap.find('[data-role="cts-capped"]'),
            $tree = $(config.treeSelector || '#tree-div'),
            names = config.names || {},
            maxResults = config.maxResults || 500,
            ids = Object.keys(names),
            lowered = ids.map(function (id) {
                return String(names[id]).toLowerCase();
            }),
            timer = null,
            preSearchOpenIds = null, // nodes the admin had open before typing; null = no active search
            $holder = $tree.closest('.tree-holder');

        // Position the box directly above the tree, inside the sidebar column.
        if ($holder.length) {
            $holder.before($wrap);
        }

        /**
         * The live jstree instance, but only once its inline data has finished loading.
         * @return {Object|null}
         */
        function instance() {
            var inst = $tree.jstree(true),
                root = inst && inst.get_node('#');

            return root && root.children && root.children.length ? inst : null;
        }

        /**
         * Matching category ids for a query, or null for a query that shouldn't filter at all
         * (empty, or too short to mean anything — except an exact id, so "5" still finds
         * category 5). The scan is linear but against the precomputed lowercase index, so it
         * holds into the tens of thousands; past that the tree itself needs server-side search.
         *
         * @param {String} query
         * @return {Array<String>|null}
         */
        function match(query) {
            var q = String(query).trim().toLowerCase(),
                out = [],
                i;

            if (!q) {
                return null;
            }

            if (q.length < MIN_QUERY) {
                return names[q] !== undefined ? [q] : null;
            }

            for (i = 0; i < ids.length; i++) {
                if (lowered[i].indexOf(q) !== -1 || // name contains
                    ids[i].indexOf(q) === 0) {      // OR id prefix
                    out.push(ids[i]);
                }
            }

            return out;
        }

        /**
         * Emphasise the actual matches (as opposed to their revealed ancestors).
         * @param {Object} inst
         * @param {Array<String>} matches
         */
        function highlight(inst, matches) {
            inst.get_container().find('.cts-hit').removeClass('cts-hit');
            matches.forEach(function (id) {
                var $node = inst.get_node(id, true);

                if ($node && $node.length) {
                    $node.children('.jstree-anchor').addClass('cts-hit');
                }
            });
        }

        /**
         * Show only the matches and their ancestor paths; hide everything else. Matches beyond
         * the cap are dropped (the caller shows a "refine your search" notice) and the ancestor
         * set is deduped before open_node so shared paths are only expanded once.
         *
         * @param {Object} inst
         * @param {Array<String>} matches - already capped to maxResults
         */
        function filter(inst, matches) {
            var reveal = {},
                ancestors = {};

            inst.hide_all(true);

            matches.forEach(function (id) {
                var node = inst.get_node(id);

                if (!node) {
                    return; // id not present in the current store's tree
                }
                reveal[id] = true;
                (node.parents || []).forEach(function (pid) {
                    if (pid !== '#') {
                        reveal[pid] = true;
                        ancestors[pid] = true;
                    }
                });
            });

            Object.keys(reveal).forEach(function (id) {
                inst.show_node(id, true);
            });
            inst.open_node(Object.keys(ancestors)); // expand each unique ancestor path once

            inst.redraw(true);
            highlight(inst, matches);
        }

        /**
         * Restore the tree to how the admin had it arranged before they started typing —
         * not a hard collapse-all, which would undo manual expansion they did before searching.
         * @param {Object} inst
         */
        function restore(inst) {
            var selected = inst.get_selected(),
                node;

            if (preSearchOpenIds === null) {
                // No search is active — nothing has been hidden or force-opened, so there is
                // nothing to restore. Crucially, don't collapse: a leading sub-min-length
                // keystroke (e.g. a lone "o") reaches here, and an unconditional close_all
                // would wipe branches the admin expanded by hand before they started typing.
                return;
            }

            inst.get_container().find('.cts-hit').removeClass('cts-hit');
            inst.show_all();
            inst.close_all();

            inst.open_node(preSearchOpenIds);
            preSearchOpenIds = null;

            if (selected.length) {
                node = inst.get_node(selected[0]);
                if (node && node.parents) {
                    inst.open_node(node.parents);
                }
            }
        }

        function run() {
            var inst = instance(),
                value,
                matches;

            if (!inst) {
                return; // tree not ready yet — the next keystroke will retry
            }

            value = $input.val();
            matches = match(value);
            $clear.toggle(value.length > 0);

            if (matches === null) {
                $empty.hide();
                $capped.hide();
                restore(inst);
                return;
            }

            if (preSearchOpenIds === null) {
                // First keystroke of a new search session — snapshot what the admin had
                // expanded so clearing later reopens exactly that, not a collapsed tree.
                preSearchOpenIds = inst.get_node('#').children_d.filter(function (id) {
                    return inst.is_open(id);
                });
            }

            filter(inst, matches.slice(0, maxResults));
            $empty.toggle(matches.length === 0);
            $capped.toggle(matches.length > maxResults);
        }

        $input.on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, DEBOUNCE_MS);
        });

        $clear.on('click', function (event) {
            event.preventDefault();
            clearTimeout(timer);
            $input.val('');
            run();
            $input.trigger('focus');
        });
    };
});
