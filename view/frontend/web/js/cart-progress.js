/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 *
 * Alpine factory for the Hyva cart page reward bar: one sentence, one bar in
 * the campaign's progress style, and the reward tiers underneath. Loaded as an
 * external, CSP-safe script (script-src 'self'); no executable inline script
 * lives in the template. The template invokes this as
 * x-data="mageenvDcpCart(<server payload>, <labels>, '<section name>')".
 *
 * Server-rendered first paint from the shared ViewModel payload, then kept
 * fresh from the "mageenv-dcp-progress" private-content section via Hyva's
 * "private-content-loaded" event. No Knockout, no arithmetic: every value is
 * read from the payload as-is and each proportional position is handed
 * straight to a CSS custom property. Mirrors the Luma component member for
 * member.
 */
window.mageenvDcpCart = function (initialState, labels, sectionName) {
    'use strict';

    var PREFIX = 'mageenv-dcp-cart';
    var PROGRESS_STYLES = {
        segmented_bar: 'segmented',
        linear_marker: 'linear',
        step_indicator: 'step',
        simple_fill: 'fill'
    };
    var DEFAULT_STYLE = 'segmented_bar';
    var LEGEND_DENSE_FROM = 5;
    var STEPS_DENSE_FROM = 5;
    var COPIED_MS = 2000;

    return {
        state: initialState || {},
        labels: labels || {},
        sectionName: sectionName,
        copied: false,

        init() {
            window.addEventListener('private-content-loaded', (event) => {
                const data = event && event.detail && event.detail.data
                    ? event.detail.data[this.sectionName]
                    : null;

                if (data) {
                    this.state = data;
                }
            });
        },

        label(key, fallback) {
            return this.labels[key] || fallback;
        },

        milestones() {
            return Array.isArray(this.state.milestones) ? this.state.milestones : [];
        },

        visible() {
            return Boolean(this.state.displayable)
                && Boolean(this.state.cartPageEnabled)
                && this.milestones().length > 0;
        },

        nextIndex() {
            return this.milestones().findIndex((milestone) => !milestone.achieved);
        },

        nextReward() {
            const index = this.nextIndex();

            return index === -1 ? null : this.milestones()[index];
        },

        allEarned() {
            return this.milestones().length > 0 && this.nextIndex() === -1;
        },

        securedRewards() {
            return this.milestones().filter((milestone) => milestone.achieved);
        },

        latestSecured() {
            const secured = this.securedRewards();

            return secured.length ? secured[secured.length - 1] : null;
        },

        rewardCode() {
            const secured = this.securedRewards();

            for (let i = secured.length - 1; i >= 0; i--) {
                if (secured[i].rewardCode) {
                    return secured[i];
                }
            }

            return null;
        },

        /**
         * Splits a translated phrase on its %1 / %2 placeholders into text parts,
         * so the template can emphasise the values without injecting HTML.
         */
        phraseParts(phrase, values) {
            const parts = [];

            phrase.split(/(%[12])/).forEach((piece) => {
                if (piece === '%1' || piece === '%2') {
                    const value = values[piece === '%1' ? 0 : 1];

                    parts.push({text: value[0], cls: PREFIX + '__em ' + PREFIX + '__em--' + value[1]});
                } else if (piece !== '') {
                    parts.push({text: piece, cls: ''});
                }
            });

            return parts;
        },

        messageParts() {
            const next = this.nextReward();
            const last = this.latestSecured();

            if (next) {
                return this.phraseParts(this.label('addMore', 'Add %1 more to unlock %2'), [
                    [next.formattedRemainingAmount, 'amount'],
                    [next.rewardLabel, 'reward']
                ]);
            }

            if (last && last.completionMessage) {
                return [{text: last.completionMessage, cls: ''}];
            }

            return last
                ? this.phraseParts(this.label('topUnlocked', '%1 unlocked, the top reward on this order'), [[last.rewardLabel, 'done']])
                : [];
        },

        unlockedNote() {
            const last = this.latestSecured();

            if (!last || this.allEarned()) {
                return '';
            }

            return last.completionMessage || this.label('unlocked', '%1 unlocked').replace('%1', last.rewardLabel);
        },

        codeLabel() {
            const milestone = this.rewardCode();

            return milestone ? this.label('codeFor', 'Code for %1:').replace('%1', milestone.rewardLabel) : '';
        },

        codeValue() {
            const milestone = this.rewardCode();

            return milestone ? milestone.rewardCode : '';
        },

        copyCode() {
            const milestone = this.rewardCode();

            if (!milestone || !window.navigator.clipboard) {
                return;
            }

            window.navigator.clipboard.writeText(milestone.rewardCode).then(() => {
                this.copied = true;
                window.setTimeout(() => {
                    this.copied = false;
                }, COPIED_MS);
            }, () => {
                this.copied = false;
            });
        },

        copyLabel() {
            return this.copied ? this.label('copied', 'Copied') : this.label('copy', 'Copy');
        },

        progressStyle() {
            const style = this.state.progressStyle;

            return Object.prototype.hasOwnProperty.call(PROGRESS_STYLES, style) ? style : DEFAULT_STYLE;
        },

        styleKey() {
            return PROGRESS_STYLES[this.progressStyle()];
        },

        styleClass() {
            return PREFIX + '__meter--' + this.styleKey();
        },

        fillValue() {
            return this.styleKey() === 'fill' ? this.state.nextPercent : this.state.overallPercent;
        },

        youLabel() {
            return this.state.formattedComparableAmount || '';
        },

        fillFromLabel() {
            const last = this.latestSecured();

            return last && !this.allEarned() ? last.formattedTargetAmount : '';
        },

        fillToParts() {
            const next = this.nextReward();

            return next
                ? this.phraseParts(this.label('at', '%1 at %2'), [[next.rewardLabel, 'reward'], [next.formattedTargetAmount, 'at']])
                : [];
        },

        markClass(milestone, index) {
            return {
                [PREFIX + '__mark--secured']: milestone.achieved,
                [PREFIX + '__mark--next']: index === this.nextIndex()
            };
        },

        /**
         * The reward tiers under the bar. Past LEGEND_DENSE_FROM milestones the
         * list keeps the next reward and its neighbours in view and folds the
         * rest into counted entries. Built from list order only.
         */
        legend() {
            const list = this.milestones();
            const next = this.nextIndex();
            const focus = next === -1 ? list.length - 1 : next;
            const dense = list.length > LEGEND_DENSE_FROM;
            const rows = [];
            let before = 0;
            let after = 0;

            list.forEach((milestone, i) => {
                if (!dense || (i >= focus - 1 && i <= focus + 1)) {
                    rows.push({key: 'm' + i, milestone: milestone, index: i, more: false});
                } else if (i < focus) {
                    before += 1;
                } else {
                    after += 1;
                }
            });

            if (before > 0) {
                rows.unshift({key: 'before', more: true, secured: true, text: this.label('earlier', '+ %1 earlier').replace('%1', before)});
            }

            if (after > 0) {
                rows.push({key: 'after', more: true, secured: false, text: this.label('more', '+ %1 more').replace('%1', after)});
            }

            return rows;
        },

        legendClass(row) {
            return {
                [PREFIX + '__legend-item--more']: row.more,
                [PREFIX + '__legend-item--secured']: row.more ? row.secured : row.milestone.achieved,
                [PREFIX + '__legend-item--next']: !row.more && row.index === this.nextIndex()
            };
        },

        stepClass(milestone, index) {
            return {
                [PREFIX + '__step--secured']: milestone.achieved,
                [PREFIX + '__step--next']: index === this.nextIndex()
            };
        },

        stepsClass() {
            return {[PREFIX + '__steps--dense']: this.milestones().length > STEPS_DENSE_FROM};
        },

        /**
         * The step box's count class (--n3 ... --n6, six or more). The CSS uses
         * it with a container query to swap the step labels for the tier list
         * when the box is too narrow for that many reward names.
         */
        stepboxClass() {
            const count = Math.min(this.milestones().length, 6);

            return count >= 3 ? {[PREFIX + '__stepbox--n' + count]: true} : {};
        },

        stepMark(milestone, index) {
            return milestone.achieved ? '✓' : String(index + 1);
        },

        tierState(milestone, index) {
            if (milestone.achieved) {
                return this.label('secured', 'Secured');
            }

            return index === this.nextIndex() ? this.label('next', 'Next reward') : this.label('upcoming', 'Upcoming');
        },

        statusMessage() {
            return this.state.primaryMessage || '';
        }
    };
};
