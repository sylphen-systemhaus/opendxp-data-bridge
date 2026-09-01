/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.layout.dd_button");
opendxp.object.layout.dd_button = Class.create(opendxp.object.abstract, {
    initialize: function (config, context) {
        this.config = config;
        this.context = context;
    },

    getLayout: function () {
        var dataport = {};

        var getDataportUrl = function(async = true) {
            Ext.Ajax.request({
                url: "/admin/SylphenDataBridge/importconfig/can-be-executed",
                async: async,
                params: {
                    id: this.context.objectId,
                    type: 'object',
                    dataportIds: [this.config.dataportId]
                },
                success: function (response) {
                    response = Ext.decode(response.responseText);
                    if (!(response && response.success)) {
                        return;
                    }

                    var dataports = response.dataports.imports.concat(response.dataports.exports);

                    if (dataports.length > 0) {
                        dataport = dataports[0];
                    } else {
                        this.component.disable();
                        this.component.setTooltip(t('pim.button.permission_missing'));
                    }
                }.bind(this)
            });
        }.bind(this);
        getDataportUrl();

        var user = opendxp.globalmanager.get("user");
        this.component = Ext.create('Ext.Button', {
            text: this.config.text,
            icon: this.config.icon,
            margin: 10,
            handler: function () {
                if(typeof dataport.url === "undefined") {
                    getDataportUrl(false);

                    if (typeof dataport.url === "undefined") {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport.notfound'), 'error');
                        return;
                    }
                }

                var runDataport = function() {
                    if (dataport.sourcetype === 'object-wizard') {
                        opendxp.plugin.Pim.plugin.startDataport(this.config.dataportId, dataport.parameters);
                    } else {
                        opendxp.plugin.Pim.plugin.runDataport(this.config.dataportId, dataport.url);
                    }
                }.bind(this);

                if (opendxp.globalmanager.exists('object_' + this.context.objectId) && opendxp.globalmanager.get('object_' + this.context.objectId).isDirty()) {
                    var panel = Ext.getCmp('object_' + this.context.objectId);

                    var saveMethod = 'version';
                    if(panel.object.data.general.published ?? panel.object.data.general.o_published) {
                        saveMethod = 'publish';
                    }
                    panel.object.save(saveMethod, null, null, function () {
                        setTimeout(runDataport, 1500); // otherwise the just saved version has same modificationDate as after dataport run and thus would not get reloaded
                    });
                } else {
                    runDataport();
                }
            }.bind(this),
            width: this.config.width ?? null,
            height: this.config.height ?? null
        });

        return this.component;
    }
});
