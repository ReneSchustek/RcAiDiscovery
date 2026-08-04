import template from './rc-ai-discovery-robots-status.html.twig';
import './rc-ai-discovery-robots-status.scss';

const { Mixin } = Shopware;

/**
 * Zeigt in der Plugin-Konfiguration pro Storefront-Sales-Channel grün/rot an, ob die relevanten
 * KI-Crawler durch die robots.txt zugelassen sind. Wird als <component> in der config.xml gerendert
 * und ruft den Admin-API-Endpoint _action/rc-ai-discovery/robots-check auf. Statusgründe kommen als
 * sprachneutrale Codes vom Backend und werden hier per Snippet übersetzt.
 */
Shopware.Component.register('rc-ai-discovery-robots-status', {
    template,

    inheritAttrs: false,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            salesChannels: [],
            isLoading: false,
        };
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            // Der authentifizierte Admin-HTTP-Client aus dem init-Container; baseURL = /api,
            // daher relativer Pfad ohne führendes /api.
            const httpClient = Shopware.Application.getContainer('init').httpClient;

            try {
                const response = await httpClient.get('_action/rc-ai-discovery/robots-check');
                this.salesChannels = response.data.salesChannels || [];
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('rc-ai-discovery.robotsStatus.loadError'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Gruppiert die Crawler nach ihrem Zweck (Suche, Abruf, Training) in fester Reihenfolge —
         * eine ungruppierte Liste aus rund 25 Einträgen wäre nicht mehr lesbar.
         */
        groupsOf(crawlers) {
            return ['search', 'fetch', 'training']
                .map((group) => ({
                    group,
                    crawlers: (crawlers || []).filter((crawler) => crawler.group === group),
                }))
                .filter((entry) => entry.crawlers.length > 0);
        },

        groupLabel(group) {
            return this.$tc(`rc-ai-discovery.robotsStatus.group.${group}`);
        },

        statusLabel(status) {
            return this.$tc(`rc-ai-discovery.robotsStatus.status.${status}`);
        },

        reasonLabel(reasonCode) {
            return this.$tc(`rc-ai-discovery.robotsStatus.reason.${reasonCode}`);
        },

        noteLabel(noteCode) {
            return noteCode ? this.$tc(`rc-ai-discovery.robotsStatus.note.${noteCode}`) : '';
        },

        itemLabel(crawler) {
            const note = this.noteLabel(crawler.noteCode);

            return `${crawler.token}: ${this.statusLabel(crawler.status)} — ${this.reasonLabel(crawler.reasonCode)}${note ? ` ${note}` : ''}`;
        },
    },
});
