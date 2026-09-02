/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */



opendxp.registerNS("opendxp.object.gridcolumn.operator.charcounter");

opendxp.object.gridcolumn.operator.dataqueryselector = Class.create(opendxp.object.gridcolumn.Abstract, {
    operatorGroup: "extractor",
    type: "operator",
    class: "DataQuerySelector",
    iconCls: "opendxp_icon_data_bridge",
    defaultText: "Data Query Selector",
    group: "getter",

    getConfigTreeNode: function(configAttributes) {
        if(configAttributes) {
            var node = {
                draggable: true,
                iconCls: this.iconCls,
                text: configAttributes.label,
                configAttributes: configAttributes,
                isTarget: true,
                allowChildren: true,
                expanded: true,
                leaf: false,
                expandable: false
            };
        } else {

            //For building up operator list
            var configAttributes = { type: this.type, class: this.class};

            var node = {
                draggable: true,
                iconCls: this.iconCls,
                text: this.getDefaultText(),
                configAttributes: configAttributes,
                isTarget: true,
                leaf: true
            };
        }
        node.isOperator = true;
        return node;
    },


    getCopyNode: function(source) {
        var copy = source.createNode({
            iconCls: this.iconCls,
            text: source.data.text,
            isTarget: true,
            leaf: false,
            expandable: false,
            isOperator: true,
            configAttributes: {
                label: source.data.text,
                type: this.type,
                class: this.class
            }
        });

        return copy;
    },


    getConfigDialog: function(node, params) {
        this.node = node;

        this.textField = new Ext.form.TextField({
            fieldLabel: t('label'),
            length: 255,
            width: 200,
            value: this.node.data.configAttributes.label
        });

        var panel = Ext.getCmp("opendxp_panel_tabs").getActiveTab();
        var classId = panel.object.search.classId;

        var pimcoreParameterStore = Ext.create('Ext.data.JsonStore', {
            fields: ['item'],
            autoload: true,
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-data-query-selector-items',
                reader: {
                    type: 'json',
                    rootProperty: 'items'
                }
            },
            listeners: {
                beforeload: function (store, operation) {
                    var el = this.dataQuerySelectorField.inputEl.dom;
                    var rng, cursorPosition = -1;
                    if (typeof el.selectionStart == "number") {
                        cursorPosition = el.selectionEnd;
                    } else if (document.selection && el.createTextRange) {
                        rng = document.selection.createRange();
                        rng.collapse(true);
                        rng.moveStart("character", -el.value.length);
                        cursorPosition = rng.text.length;
                    }

                    var sourceClass = classId;

                    operation.setParams({
                        value: this.dataQuerySelectorField.getValue(),
                        cursorPosition: cursorPosition,
                        sourceClass: sourceClass
                    });

                    return true;
                }.bind(this)
            }
        });

        this.dataQuerySelectorField = new Ext.form.field.ComboBox({
            fieldLabel: t('pim.dataport.opendxp.fields.data_query_selector'),
            value: this.node.data.configAttributes.dataQuerySelector,
            allowBlank: false,
            hideTrigger: true,
            typeAhead: true,
            minChars: 0,
            tpl: Ext.create('Ext.XTemplate',
                '<tpl for=".">',
                '<li class="x-boundlist-item">{label}',
                '</li>',
                '</tpl>'
            ),
            displayField: 'value',
            valueField: 'value',
            store: pimcoreParameterStore,
            triggerAction: 'all',
            listeners: {
                select: function (combo, record) {
                    if ([':', '('].indexOf(combo.value[combo.value.length - 1]) > -1) {
                        pimcoreParameterStore.on('load', function () {
                            this.dataQuerySelectorField.expand();
                        }.bind(this), {
                            single: true
                        });
                        pimcoreParameterStore.load();
                    }
                }.bind(this),
                render: function (cmp) {
                    Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                        e.stopPropagation();
                    });
                }
            }
        });


        this.configPanel = new Ext.Panel({
            layout: "form",
            bodyStyle: "padding: 10px;",
            items: [this.textField, this.dataQuerySelectorField],
            buttons: [{
                text: t("apply"),
                iconCls: "opendxp_icon_apply",
                handler: function () {
                    this.commitData(params);
                }.bind(this)
            }]
        });

        this.window = new Ext.Window({
            width: 400,
            height: 200,
            modal: true,
            title: this.getDefaultText(),
            layout: "fit",
            items: [this.configPanel]
        });

        this.window.show();
        return this.window;
    },

    commitData: function(params) {
        this.node.data.configAttributes.label = this.textField.getValue();
        this.node.data.configAttributes.dataQuerySelector = this.dataQuerySelectorField.getValue();
        this.node.set('text', this.textField.getValue());
        this.node.set('isOperator', true);
        this.window.close();

        if (params && params.callback) {
            params.callback();
        }
    }
});