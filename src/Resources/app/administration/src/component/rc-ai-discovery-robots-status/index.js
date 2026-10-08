import template from './rc-ai-discovery-robots-status.html.twig';
import './rc-ai-discovery-robots-status.scss';

const { Mixin } = Shopware;

/**
 * Zeigt in der Plugin-Konfiguration je Storefront-Kanal, welche KI-Crawler die robots.txt zulässt:
 * grün erlaubt, rot gesperrt, grau unbekannt. Eingebunden als `<component>` in der `config.xml`,
 * Daten von `_action/rc-ai-discovery/robots-check`. Gründe und Hinweise kommen als sprachneutrale
 * Codes und werden hier über die Textbausteine übersetzt.
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

            // Der angemeldete HTTP-Client der Verwaltung; seine Basis ist `/api`, der Pfad beginnt
            // deshalb ohne `/api`.
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
         * Gruppiert die Crawler nach Zweck in der festen Reihenfolge Suche, Abruf, Training. Ungruppiert
         * stünde der ganze Katalog, derzeit 28 Einträge, in einer Liste. Leere Gruppen fallen weg.
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

        // Bildschirmleser bekommen Status, Grund und Hinweis in einem Satz; sichtbar steht der Grund
        // nur im `title` beim Überfahren.
        itemLabel(crawler) {
            const note = this.noteLabel(crawler.noteCode);

            return `${crawler.token}: ${this.statusLabel(crawler.status)} — ${this.reasonLabel(crawler.reasonCode)}${note ? ` ${note}` : ''}`;
        },
    },
});
