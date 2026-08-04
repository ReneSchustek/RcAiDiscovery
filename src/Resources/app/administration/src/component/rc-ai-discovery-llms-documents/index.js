import template from './rc-ai-discovery-llms-documents.html.twig';
import './rc-ai-discovery-llms-documents.scss';

const { Mixin } = Shopware;

/**
 * Zeigt die gespeicherten llms-Dateien mit Stand und Zustand und bietet die drei Aktionen
 * „jetzt aktualisieren", „bearbeiten/speichern" und „neu generieren". Bearbeitete Dateien
 * werden von der geplanten Generierung nicht mehr überschrieben — deshalb ist der Zustand
 * sichtbar und „neu generieren" der bewusste Weg zurück zur Automatik.
 */
Shopware.Component.register('rc-ai-discovery-llms-documents', {
    template,

    inheritAttrs: false,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            documents: [],
            isLoading: false,
            editingId: null,
            draft: '',
        };
    },

    created() {
        this.load();
    },

    methods: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        async request(call) {
            this.isLoading = true;

            try {
                const response = await call(this.httpClient());
                this.documents = response.data.documents || [];

                return true;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('rc-ai-discovery.llmsDocuments.error'),
                });

                return false;
            } finally {
                this.isLoading = false;
            }
        },

        load() {
            return this.request((client) => client.get('_action/rc-ai-discovery/llms-documents'));
        },

        async refresh() {
            const ok = await this.request((client) => client.post('_action/rc-ai-discovery/llms-documents/refresh'));

            if (ok) {
                this.createNotificationSuccess({
                    message: this.$tc('rc-ai-discovery.llmsDocuments.refreshed'),
                });
            }
        },

        edit(document) {
            this.editingId = document.id;
            this.draft = document.content;
        },

        cancelEdit() {
            this.editingId = null;
            this.draft = '';
        },

        async save(document) {
            const ok = await this.request((client) => client.patch(
                `_action/rc-ai-discovery/llms-documents/${document.id}/content`,
                { content: this.draft },
            ));

            if (ok) {
                this.cancelEdit();
                this.createNotificationSuccess({
                    message: this.$tc('rc-ai-discovery.llmsDocuments.saved'),
                });
            }
        },

        async regenerate(document) {
            const ok = await this.request((client) => client.post(
                `_action/rc-ai-discovery/llms-documents/${document.id}/regenerate`,
            ));

            if (ok) {
                this.cancelEdit();
                this.createNotificationSuccess({
                    message: this.$tc('rc-ai-discovery.llmsDocuments.regenerated'),
                });
            }
        },

        variantLabel(variant) {
            return this.$tc(`rc-ai-discovery.llmsDocuments.variant.${variant}`);
        },

        stateLabel(isCustom) {
            return isCustom
                ? this.$tc('rc-ai-discovery.llmsDocuments.state.custom')
                : this.$tc('rc-ai-discovery.llmsDocuments.state.auto');
        },

        formatDate(value) {
            return value ? new Date(value).toLocaleString() : '';
        },
    },
});
