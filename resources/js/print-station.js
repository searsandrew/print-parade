export function createPrintStation() {
    return {
        catalog: { templates: [], printers: [], operators: [], authorization: { requires_operator_pin: false } },
        templateId: '',
        printerId: '',
        employeeId: '',
        quantity: 1,
        pin: '',
        values: {},
        loading: true,
        submitting: false,
        error: '',
        confirmation: null,
        catalogPoller: null,
        catalogRefreshing: false,

        async init() {
            await this.refreshCatalog(true);
            this.catalogPoller = window.setInterval(() => {
                if (!document.hidden) {
                    void this.refreshCatalog();
                }
            }, 15000);
        },

        destroy() {
            window.clearInterval(this.catalogPoller);
        },

        async refreshCatalog(initial = false) {
            if (this.catalogRefreshing) {
                return;
            }

            this.catalogRefreshing = true;

            try {
                const response = await fetch('/print/catalog', {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('The print catalog could not be loaded.');
                }

                this.catalog = await response.json();
            } catch (error) {
                if (initial) {
                    this.error = error.message;
                }
            } finally {
                this.catalogRefreshing = false;

                if (initial) {
                    this.loading = false;
                }
            }
        },

        get requiresOperatorPin() {
            return this.catalog.authorization.requires_operator_pin;
        },

        get selectedTemplate() {
            return this.catalog.templates.find((template) => String(template.id) === String(this.templateId));
        },

        get availablePrinters() {
            if (!this.selectedTemplate) return [];

            return this.catalog.printers.filter(
                (printer) => String(printer.label_stock_id) === String(this.selectedTemplate.stock.id),
            );
        },

        templateChanged() {
            this.printerId = '';
            this.confirmation = null;
            this.resetLabelInputs();
        },

        resetLabelInputs() {
            this.values = {};

            if (!this.selectedTemplate) {
                return;
            }

            Object.entries(this.selectedTemplate.fields).forEach(([name, field]) => {
                this.values[name] = field.default ?? (field.type === 'boolean' ? false : '');
            });
        },

        inputType(field) {
            if (field.type === 'number') return 'number';
            if (field.type === 'date') return 'date';
            return 'text';
        },

        async submit() {
            this.error = '';
            this.confirmation = null;
            this.submitting = true;

            try {
                const response = await fetch('/print/jobs', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        label_template_id: Number(this.templateId),
                        printer_id: Number(this.printerId),
                        employee_id: this.requiresOperatorPin ? Number(this.employeeId) : null,
                        pin: this.requiresOperatorPin ? this.pin : null,
                        quantity: Number(this.quantity),
                        values: this.values,
                    }),
                });
                const result = await response.json();

                if (!response.ok) {
                    const message = Object.values(result.errors ?? {}).flat()[0];
                    throw new Error(message ?? result.message ?? 'The print job could not be submitted.');
                }

                this.confirmation = result;
                this.resetLabelInputs();
                this.quantity = 1;
                this.pin = '';
            } catch (error) {
                this.error = error.message;
            } finally {
                this.submitting = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('printStation', createPrintStation);
});
