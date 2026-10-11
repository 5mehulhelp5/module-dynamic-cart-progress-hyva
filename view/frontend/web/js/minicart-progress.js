/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 *
 * Alpine factory for the Hyva Mini Cart Reward Card, at a fixed height: the
 * next reward in the display face with the distance beside it, the secured
 * count (or the earned reward code), and one progress line in the campaign's
 * style. Loaded as an external, CSP-safe script (script-src 'self'); no
 * executable inline script lives in the template. The template invokes this as
 * x-data="mageenvDcpMinicart(<server payload>, <labels>, '<section name>')".
 *
 * Server-rendered first paint from the shared ViewModel payload, then kept
 * fresh from the "mageenv-dcp-progress" private-content section via Hyva's
 * "private-content-loaded" event. No Knockout, no arithmetic: each
 * proportional position is handed straight to a CSS custom property. Mirrors
 * the Luma component member for member.
 */
window.mageenvDcpMinicart = function (initialState, labels, sectionName) {
    'use strict';

    const PREFIX = 'mageenv-dcp-minicart';
    const PROGRESS_STYLES = {
        segmented_bar: 'segmented',
        linear_marker: 'linear',
        step_indicator: 'step',
        simple_fill: 'fill'
    };
    const DEFAULT_STYLE = 'segmented_bar';
    const DENSE_FROM = 6;
    const COPIED_MS = 2000;

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
                && Boolean(this.state.minicartEnabled)
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

        rewardCode() {
            const secured = this.securedRewards();

            for (let i = secured.length - 1; i >= 0; i--) {
                if (secured[i].rewardCode) {
                    return secured[i];
                }
            }

            return null;
        },

        kickerLabel() {
            return this.allEarned() ? this.label('rewards', 'Rewards') : this.label('next', 'Next reward');
        },

        securedNote() {
            const count = this.securedRewards().length;

            return count > 0 && !this.allEarned()
                ? this.label('secured', '%1 secured').replace('%1', count)
                : '';
        },

        codeValue() {
            const milestone = this.rewardCode();

            return milestone ? milestone.rewardCode : '';
        },

        codeBadgeLabel() {
            return this.copied ? this.label('copied', 'Copied') : this.label('code', 'Code');
        },

        codeAria() {
            const milestone = this.rewardCode();

            return milestone ? this.label('copyCode', 'Copy code %1').replace('%1', milestone.rewardCode) : '';
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

        offerLabel() {
            const reward = this.nextReward();
            const list = this.milestones();

            if (reward) {
                return reward.rewardLabel;
            }

            return list.length ? list[list.length - 1].rewardLabel : '';
        },

        distanceLabel() {
            const reward = this.nextReward();

            return reward
                ? this.label('away', 'Just %1 away').replace('%1', reward.formattedRemainingAmount)
                : this.label('done', 'Every reward secured');
        },

        thenLabel() {
            const after = this.nextIndex() === -1 ? null : this.milestones()[this.nextIndex() + 1];

            return after ? this.label('then', 'then %1').replace('%1', after.rewardLabel) : '';
        },

        progressStyle() {
            const style = this.state.progressStyle;

            return Object.prototype.hasOwnProperty.call(PROGRESS_STYLES, style) ? style : DEFAULT_STYLE;
        },

        styleKey() {
            return PROGRESS_STYLES[this.progressStyle()];
        },

        styleClass() {
            let cls = PREFIX + '__meter--' + this.styleKey();

            if (this.milestones().length > DENSE_FROM) {
                cls += ' ' + PREFIX + '__meter--dense';
            }

            return cls;
        },

        fillValue() {
            return this.styleKey() === 'fill' ? this.state.nextPercent : this.state.overallPercent;
        },

        msClass(milestone, index) {
            return {
                [PREFIX + '__ms--secured']: milestone.achieved,
                [PREFIX + '__ms--next']: index === this.nextIndex()
            };
        },

        stepClass(milestone, index) {
            return {
                [PREFIX + '__step--secured']: milestone.achieved,
                [PREFIX + '__step--next']: index === this.nextIndex()
            };
        },

        stepMark(milestone, index) {
            return milestone.achieved ? '✓' : String(index + 1);
        },

        /**
         * Milestone detail - built from one existing milestone row,
         * no arithmetic. Three short lines for the on-hover / on-focus surface;
         * milestoneAria() is the trigger button's accessible name.
         */
        milestoneReach(milestone) {
            return milestone.achieved
                ? this.label('reachedAt', 'Unlocked at %1').replace('%1', milestone.formattedTargetAmount)
                : this.label('reach', 'Reach %1').replace('%1', milestone.formattedTargetAmount);
        },

        milestoneStatus(milestone) {
            return milestone.achieved
                ? this.label('milestoneSecured', 'Secured')
                : this.label('milestoneMore', '%1 more').replace('%1', milestone.formattedRemainingAmount);
        },

        milestoneAria(milestone) {
            return milestone.achieved
                ? this.label('ariaSecured', '%1: secured, reached at %2')
                    .replace('%1', milestone.rewardLabel)
                    .replace('%2', milestone.formattedTargetAmount)
                : this.label('ariaUpcoming', '%1: reach %2, %3 more')
                    .replace('%1', milestone.rewardLabel)
                    .replace('%2', milestone.formattedTargetAmount)
                    .replace('%3', milestone.formattedRemainingAmount);
        },

        statusMessage() {
            return this.state.primaryMessage || '';
        }
    };
};
