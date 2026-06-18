(function () {
    function initializeUnitPicker(picker) {
        var primary = picker.querySelector('[data-primary-unit]');
        var list = picker.querySelector('[data-additional-units]');
        var template = picker.querySelector('[data-unit-row-template]');
        var addButton = picker.querySelector('[data-add-unit]');

        if (!primary || !list || !template || !addButton) {
            return;
        }

        function additionalSelects() {
            return Array.prototype.slice.call(list.querySelectorAll('[data-additional-unit]'));
        }

        function refreshOptions() {
            var primaryValue = primary.value;
            var selects = additionalSelects();
            var selectedValues = selects
                .map(function (select) { return select.value; })
                .filter(Boolean);

            selects.forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (option) {
                    if (!option.value) {
                        option.disabled = false;
                        return;
                    }

                    option.disabled = option.value === primaryValue
                        || (option.value !== select.value && selectedValues.indexOf(option.value) !== -1);
                });
            });

            addButton.disabled = 1 + selects.length >= primary.options.length;
        }

        function addRow() {
            var fragment = template.content.cloneNode(true);
            var row = fragment.querySelector('[data-unit-row]');
            var select = fragment.querySelector('[data-additional-unit]');

            list.appendChild(fragment);
            refreshOptions();

            if (select) {
                select.focus();
            }
            if (row) {
                row.scrollIntoView({ block: 'nearest' });
            }
        }

        addButton.addEventListener('click', addRow);

        primary.addEventListener('change', function () {
            additionalSelects().forEach(function (select) {
                if (select.value === primary.value) {
                    select.value = '';
                }
            });
            refreshOptions();
        });

        list.addEventListener('change', function (event) {
            if (event.target.matches('[data-additional-unit]')) {
                refreshOptions();
            }
        });

        list.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-unit]');
            if (!removeButton) {
                return;
            }

            var row = removeButton.closest('[data-unit-row]');
            if (row) {
                row.remove();
                refreshOptions();
                addButton.focus();
            }
        });

        refreshOptions();
    }

    document.querySelectorAll('[data-unit-picker]').forEach(initializeUnitPicker);
})();
