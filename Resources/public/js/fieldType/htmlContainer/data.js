/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.classes.layout.dd_htmlContainer");
opendxp.object.classes.layout.dd_htmlContainer = Class.create(opendxp.object.classes.layout.layout, {
    type: "dd_htmlContainer",

    initialize: function (treeNode, initData) {
        this.type = "dd_htmlContainer";

        this.initData(initData);

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("dd_htmlContainer");
    },

    getIconClass: function () {
        return "opendxp_icon_tag";
    },

    getLayout: function ($super) {
        $super();

        this.previewPanel = new Ext.Panel({
            layout: 'fit',
            height: 500,
            html: '<iframe src="about:blank" style="width: 100%; height: 100%;" frameborder="0" id="text-layout-preview_' + this.id + '"></iframe>',
        });

        this.specificSettingsForm = new Ext.form.FormPanel({
            title: t("specific_settings"),
            bodyStyle: "padding: 10px;",
            autoScroll: true,
            style: "margin: 10px 0 10px 0",
            items: [
                {
                    xtype: "checkbox",
                    fieldLabel: t("border"),
                    name: "border",
                    checked: this.datax.border,
                },
                {
                    xtype: "textfield",
                    name: "className",
                    value: this.datax.className,
                },
                {
                    xtype: 'container',
                    style: 'padding-top:10px;',
                    html: 'You can access field values of the current object via <pre style="display: inline">{{ }}</pre> placeholders, e.g. <pre style="display: inline">This object\'s id is {{ id }}</pre><br>For more complex requirements you can use data query selectors and Twig syntax:'
                },
                {
                    xtype: "htmleditor",
                    cls: 'objectlayout_element_text',
                    height: 300,
                    value: this.datax.html,
                    name: "html",
                    enableSourceEdit: true,
                    enableFont: false,
                    listeners: {
                        initialize: function (el) {
                            var head = el.getDoc().head;
                            var link = document.createElement("link");

                            link.type = "text/css";
                            link.rel = "stylesheet";
                            link.href = '/bundles/opendxpadmin/css/admin.css';

                            head.appendChild(link);
                        }
                    }
                }
            ]
        });

        this.layout.add(this.specificSettingsForm);

        return this.layout;
    },

    loadPreview: function () {
        let params = this.specificSettingsForm.getForm().getFieldValues();
        var url = Routing.generate('opendxp_admin_dataobject_class_textlayoutpreview', params);

        try {
            Ext.get('text-layout-preview_' + this.id).dom.src = url;
        } catch (e) {
            console.log(e);
        }
    }
});