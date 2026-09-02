/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.plugin");

opendxp.plugin.Pim.plugin = Class.create({
    checkForUpdateElements: {},
    lastHistoryItem: null,
    historyItems: [],
    historyIndex: null,
    defaultLanguage: null,
    guiTranslationDetails: [],

    getClassName: function () {
        return "opendxp.plugin.Pim.plugin";
    },

    initialize: function () {
        if(typeof opendxp.events !== 'undefined') {
            Ext.Object.each(opendxp.events, function(eventName) {
                document.addEventListener(opendxp.events[eventName], (e) => {
                    if(typeof this[eventName] === 'function') {
                        this[eventName].apply(this, Object.values(e.detail));
                    }
                });
            }.bind(this));
        } else {
            opendxp.plugin.broker.registerPlugin(this);
        }

        this.guiTranslationDetails = this.readGuiTranslationDetails();
    },

    readGuiTranslationDetails: function () {
        let details = [];
        Ext.Ajax.request({
            url: '/SylphenDataBridge/read-gui-translation-details',
            async: false,
            method: 'GET',
            success: function (response) {
                if (JSON.parse(response.responseText).success === true) {
                    details = JSON.parse(response.responseText).data;
                }
            },
            failure: function (response) {
                console.error([
                    'Failed to fetch whether editor tab titles must be translated.',
                    JSON.parse(response.responseText).message
                ]);
            }
        });

        return details;
    },

    translateGuiElement: function (element) {
        if (!(this.guiTranslationDetails?.[element.type]?.active ?? true)) {
            return element.key;
        }

        let prefix = this.guiTranslationDetails?.[element.type]?.translation_key_prefix ?? '';
        let translated = t(prefix + element.key);

        if (translated !== prefix + element.key) {
            return translated;
        }

        return t(element.key);
    },

    uninstall: function () {
        //TODO remove from menu
    },

    opendxpReady: function () {
        var user = opendxp.globalmanager.get("user");

        var allowed = user.isAllowed('plugin_sylphen_data_bridge');
        if(!allowed) {
            for(var i in user.permissions) {
                if(user.permissions[i].indexOf('Dataport') > -1) {
                    allowed = true;
                    break;
                }
            }
        }

        if (!allowed) {
            return;
        }

        var importConfigID = "data-bridge";

        var mouseDownListener = function (e, el) {
            try {
                opendxp.globalmanager.get(importConfigID).activate();
            } catch (e) {
                opendxp.globalmanager.add(importConfigID, new opendxp.plugin.Pim.ImportConfig(importConfigID));

            }
        }.bind(this);

        if(document.body.classList.contains('opendxp_version_1')) {
            Ext.get('opendxp_navigation').down('ul').insertHtml('beforeEnd', '<li id="opendxp_menu_data_bridge" data-menu-tooltip="' + t("pim.PIM config") + '" class="opendxp_menu_item"><img src="/bundles/opendxpadmin/img/flat-white-icons/feed_in.svg"></li>');

            Ext.get('opendxp_menu_data_bridge').on('mouseover', function (e, el) {
                var imgElement = el.getElementsByTagName('img');
                if(imgElement.length > 0) {
                    imgElement[0].src = '/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg';
                }
            });
            Ext.get('opendxp_menu_data_bridge').on('mouseleave', function (e, el) {
                var imgElement = el.getElementsByTagName('img');
                if (imgElement.length > 0) {
                    imgElement[0].src = '/bundles/opendxpadmin/img/flat-white-icons/feed_in.svg';
                }
            });

            if(window.getComputedStyle(document.getElementById('opendxp_sidebar')).backgroundColor === "rgb(255, 255, 255)") {
                document.getElementById('opendxp_menu_data_bridge').style.filter = 'invert(0.7)';
            }
        } else {
            Ext.get('opendxp_navigation').down('ul').insertHtml('beforeEnd', '<li id="opendxp_menu_data_bridge" data-menu-tooltip="' + t("pim.PIM config") + '" class="opendxp_menu_item"><img src="/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg"></li>');
        }

        Ext.get('opendxp_menu_data_bridge').on('mousedown', mouseDownListener);

        opendxp.helpers.initMenuTooltips(); // Pimcore fires this too early, not even the Pimcore default menu items are loaded here yet

        Ext.Ajax.request({
            url: "/admin/SylphenDataBridge/importconfig/get-favorites",
            success: function (response) {
                response = Ext.decode(response.responseText);
                if (!(response && response.success)) {
                    return;
                }

                var subMenu = Ext.create('Ext.menu.Menu', {
                    shadow: false,
                    cls: "opendxp_navigation_flyout"
                });

                var type = null;
                Ext.each(response.dataports, function (dataport) {
                    var menuItem = {
                        text: dataport.name,
                        icon: dataport.icon,
                        iconCls: dataport.type === 'export' ? "opendxp_icon_overlay_upload" : "opendxp_icon_overlay_download"
                    };

                    if(type !== null && type !== dataport.type) {
                        subMenu.add('-');
                    }
                    type = dataport.type;

                    menuItem.listeners = {
                        click: function () {
                            var manualImport = new opendxp.plugin.Pim.ManualImport({ dataportId: dataport.id, sourceType: dataport.sourceType });
                            manualImport.getStartWindow().getComponent('startForm').getForm().findField('importType').setValue('complete');
                            //manualImport.getStartWindow().getComponent('startForm').getForm().findField('dry-run').setValue(true);
                            manualImport.isExport = dataport.type === 'export';

                            manualImport.getStartWindow().setTitle(dataport.type === 'export' ? t('pim.manual.startimport.complete.export') : t('pim.manual.startimport.complete'));
                            var startButton = manualImport.getStartWindow().queryById("btnSave");
                            startButton.setText(manualImport.isExport ? t('pim.manual.startimport.complete.export') : t('pim.manual.startimport.complete'));
                            manualImport.getStartWindow().show();
                            Ext.each(manualImport.getStartWindow().getComponent('startForm').getForm().getFields().items, function (field) {
                                if (field.isVisible()) {
                                    field.focus();
                                    return false;
                                }
                            });
                        }
                    };

                    subMenu.add(menuItem);
                });

                if(subMenu.items.length > 0) {
                    subMenu.add('-');
                    subMenu.add({
                        text: t("pim.dataport_configuration"),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg",
                        handler: function () {
                            try {
                                opendxp.globalmanager.get(importConfigID).activate();
                            } catch (e) {
                                opendxp.globalmanager.add(importConfigID, new opendxp.plugin.Pim.ImportConfig(importConfigID));
                            }
                        }
                    });


                    var showSubMenu = function (e) {
                        if (subMenu.hidden) {
                            e.stopEvent();
                            var el = Ext.get(e.currentTarget);
                            var offsets = el.getOffsetsTo(Ext.getBody());
                            offsets[0] = 60;
                            subMenu.showAt(offsets);
                        } else {
                            subMenu.hide();
                        }
                    };
                    Ext.get('opendxp_menu_data_bridge').on('mouseenter', showSubMenu);
                }
            }
        });

        if (user.memorizeTabs || opendxp.helpers.forceOpenMemorizedTabsOnce()) {
            var openTabs = opendxp.helpers.getOpenTab();
            var openedTabs = [];

            var dataportsPanel;
            for (var i = 0; i < openTabs.length; i++) {
                if (openTabs[i] && openedTabs.indexOf(openTabs[i]) === -1) {
                    var parts = openTabs[i].split("_");
                    if (parts[1] && parts[0] === "dataport") {
                        try {
                            dataportsPanel = opendxp.globalmanager.get(importConfigID);
                            dataportsPanel.activate();
                        } catch (e) {
                            dataportsPanel = new opendxp.plugin.Pim.ImportConfig(importConfigID);
                            opendxp.globalmanager.add(importConfigID, dataportsPanel);
                        }
                        break;
                    }
                }
            }

            if(dataportsPanel) {
                dataportsPanel.dataStore.on('load', function () {
                    for (var i = 0; i < openTabs.length; i++) {
                        if (openTabs[i] && openedTabs.indexOf(openTabs[i]) === -1) {
                            var parts = openTabs[i].split("_");
                            if (parts[1] && parts[0] === "dataport") {
                                dataportsPanel.id = parts[1];
                                dataportsPanel.onTreeNodeClick();
                            }
                        }
                        openedTabs.push(openTabs[i]);
                    }
                }, this, { single: true });
            }
        }

        var fileMenu = opendxp.globalmanager.get("layout_toolbar").fileMenu;
        if(fileMenu) {
            var openDataObjectMenuItem = fileMenu.queryById('opendxp_menu_file_open_data_object');
            if (!openDataObjectMenuItem) {
                Ext.each(fileMenu.items.items, function (fileMenuItem) {
                    if (fileMenuItem.text === t("open_data_object")) {
                        openDataObjectMenuItem = fileMenuItem;
                        return false;
                    }
                });
            }

            if(openDataObjectMenuItem) {
                Ext.Ajax.request({
                    url: "/admin/SylphenDataBridge/importconfig/get-search-by-field-fields",
                    ignoreErrors: true,
                    success: function (response) {
                        response = Ext.decode(response.responseText);
                        if (!(response && response.success)) {
                            return;
                        }

                        var searchMenuItems = [];
                        Ext.each(response.searchFields, function(searchField) {
                            searchMenuItems.push({
                                text: t("pim.searchByField").replace('%class%', t(searchField.className)).replace('%field%', t(searchField.fieldName)),
                                icon: searchField.icon,
                                handler: function () {
                                    Ext.MessageBox.prompt(
                                        t("pim.searchByField").replace('%class%', t(searchField.className)).replace('%field%', t(searchField.fieldName)),
                                        t("pim.searchByField.prompt").replace('%class%', t(searchField.className)).replace('%field%', t(searchField.fieldName)),
                                        function (button, value, object) {
                                            if (button === "ok" && value) {
                                                Ext.Ajax.request({
                                                    url: "/admin/SylphenDataBridge/importconfig/open-objects-by-search-field",
                                                    params: {
                                                        classId: searchField.classId,
                                                        field: searchField.field,
                                                        value: value
                                                    },
                                                    success: function (response) {
                                                        response = Ext.decode(response.responseText);
                                                        if (!(response && response.success)) {
                                                            return;
                                                        }

                                                        if(response.ids.length === 0) {
                                                            opendxp.helpers.showNotification(t("error"), t("pim.searchByField.not_found").replace('%class%', t(searchField.className)).replace('%field%', t(searchField.fieldName)).replace('%value%', value), "error");
                                                        }

                                                        Ext.Array.each(response.ids, function(id) {
                                                            opendxp.helpers.openElement(id, 'object', searchField.className);
                                                        });
                                                    }
                                                });
                                            }
                                        }
                                    );
                                }
                            });
                        });

                        if(searchMenuItems.length > 0) {
                            searchMenuItems.unshift({
                                text: t("pim.searchByField").replace('%class%', t('object')).replace('%field%', t('id')+" / "+t('path')),
                                iconCls: "opendxp_nav_icon_object opendxp_icon_overlay_go",
                                handler: opendxp.helpers.openElementByIdDialog.bind(this, "object")
                            }, '-');
                            openDataObjectMenuItem.setMenu({
                                cls: "opendxp_navigation_flyout",
                                shadow: false,
                                items: searchMenuItems
                            });
                        }
                    }
                });
            }

            var historyMenuItem = this.getHistoryMenuitem();
            if(historyMenuItem) {
                var history = opendxp.helpers.getHistory();
                var historyMenuItems = [];
                Ext.Array.each(history.slice(0, 10), function(historyItem) {
                    var path = historyItem.name.split('/').pop();

                    var menuItemData = {
                        text: path || t('home'),
                        handler: function () {
                            opendxp.helpers.openElement(historyItem.id, historyItem.type);
                        }
                    }

                    if(typeof historyItem.icon !== "undefined" && historyItem.icon) {
                        menuItemData.icon = historyItem.icon;
                    } else if(typeof historyItem.iconCls !== "undefined" && historyItem.iconCls) {
                        menuItemData.iconCls = historyItem.iconCls;
                    } else {
                        menuItemData.iconCls = "opendxp_nav_icon_object opendxp_icon_overlay_go";
                    }

                    historyMenuItems.push(menuItemData);
                });

                historyMenuItem.setMenu({
                    cls: "opendxp_navigation_flyout",
                    shadow: false,
                    items: historyMenuItems
                });
            }
        }

        var saveTabChangeState = true;

        Ext.getCmp("opendxp_panel_tabs").on('tabchange', function(tabPanel, panel) {
            if (saveTabChangeState && (this.lastHistoryItem === null || this.lastHistoryItem !== panel.getId())) {
                this.lastHistoryItem = panel.getId();
                var urlHash = panel.getId();
                if (typeof panel.object !== "undefined") {
                    urlHash = 'object-'+panel.object.id;
                } else if (typeof panel.asset !== "undefined") {
                    urlHash = 'asset-' + panel.asset.id;
                } else if (typeof panel.document !== "undefined") {
                    urlHash = 'document-' + panel.document.id;
                }
                try {
                    window.history.pushState(panel.getId(), "", '#' + urlHash);
                    this.historyItems.push(panel.getId());
                    this.historyIndex = this.historyItems.length - 1;
                } catch(e) {
                }
            }
        }.bind(this));

        window.addEventListener("popstate", (event) => {
            if (event.state) {
                var direction = 'back';
                if (this.historyIndex < this.historyItems.length && this.historyItems[this.historyIndex + 1] == event.state) {
                    direction = 'forward';
                    this.historyIndex++;
                } else {
                    this.historyIndex--;
                }

                if(Ext.getCmp("opendxp_panel_tabs").down('#'+ event.state)) {
                    saveTabChangeState = false;
                    Ext.getCmp("opendxp_panel_tabs").setActiveItem(event.state);
                    saveTabChangeState = true;
                    curState = null;
                } else {
                    if(direction === 'forward') {
                        window.history.forward();
                    } else {
                        window.history.back();
                    }
                }
            }
        });

        var tabCloseMenu = Ext.getCmp("opendxp_panel_tabs").getPlugin('tabclosemenu');
        var tabMenu = tabCloseMenu.extraItemsTail;
        if (tabMenu === null) {
            tabMenu = [];
        }
        tabMenu.push({
            text: t('pim.dataport.tab.close_left'),
            handler: function (item, test, test1) {
                var tabPanel = Ext.getCmp("opendxp_panel_tabs");
                var activeTabIndex = tabPanel.items.indexOf(tabCloseMenu.item);

                var tabsToBeClosed = [];
                Ext.each(tabPanel.items.items, function(tab, index) {
                    if(index < activeTabIndex) {
                        tabsToBeClosed.push(tab);
                    }
                });

                Ext.each(tabsToBeClosed, function(tab) {
                    tab.close();
                });
            }.bind(this)
        });
        tabMenu.push({
            text: t('pim.dataport.tab.close_right'),
            handler: function (item) {
                var tabPanel = Ext.getCmp("opendxp_panel_tabs");
                var activeTabIndex = tabPanel.items.indexOf(tabCloseMenu.item);

                var tabsToBeClosed = [];
                Ext.each(tabPanel.items.items, function (tab, index) {
                    if (index > activeTabIndex) {
                        tabsToBeClosed.push(tab);
                    }
                });

                Ext.each(tabsToBeClosed, function (tab) {
                    tab.close();
                });
            }.bind(this)
        });
        Ext.getCmp("opendxp_panel_tabs").getPlugin('tabclosemenu').extraItemsTail = tabMenu;

        if (user.admin) {
            var extrasMenu = opendxp.globalmanager.get("layout_toolbar").extrasMenu;
            if (extrasMenu) {
                var systemMenu = extrasMenu.queryById('opendxp_menu_extras_system_info');
                if (!systemMenu) {
                    Ext.each(extrasMenu.items.items, function (extraMenuItem) {
                        if (extraMenuItem.text === t("system_infos_and_tools")) {
                            systemMenu = extraMenuItem;
                            return false;
                        }
                    });
                }

                if (systemMenu) {
                    var adminerMenuItem = null;
                    var systemMenuItems = systemMenu.menu.items.items;
                    Ext.each(systemMenuItems, function (systemMenuItem) {
                        if (systemMenuItem.itemId === 'opendxp_menu_extras_system_info_database_administration' || systemMenuItem.text === t("database_administration")) {
                            adminerMenuItem = systemMenuItem;
                            return false;
                        }
                    });

                    if (adminerMenuItem === null) {
                        systemMenu.menu.add({
                            text: t("database_administration"),
                            iconCls: "opendxp_nav_icon_mysql",
                            handler: function () {
                                opendxp.helpers.openGenericIframeWindow("adminer", "/admin/SylphenDataBridgeBundle/adminer", "opendxp_icon_mysql", "Database Admin");
                            }
                        });
                    } else {
                        adminerMenuItem.setHandler(function () {
                            opendxp.helpers.openGenericIframeWindow("adminer", "/admin/SylphenDataBridgeBundle/adminer", "opendxp_icon_mysql", "Database Admin");
                        });
                    }
                }
            }
        }

        (function refresh () {
            setTimeout(function () {
                var requestElements = [];
                for (var elementKey in this.checkForUpdateElements) {
                    var element = this.checkForUpdateElements[elementKey];

                    requestElements.push({id: element.id, type: element.type});
                }

                if(document.hasFocus() && requestElements.length > 0) {
                    Ext.Ajax.request({
                        url: "/admin/SylphenDataBridge/import/has-element-changed",
                        method: "post",
                        params: {
                            elements: JSON.stringify(requestElements)
                        },
                        ignoreErrors: true,
                        success: function (response) {
                            response = Ext.decode(response.responseText);
                            if (!(response && response.success)) {
                                return;
                            }

                            Ext.each(response.elements, function(element) {
                                if (!opendxp.globalmanager.exists(element.type + '_' + element.id)) {
                                    delete this.checkForUpdateElements[element.type + '_' + element.id];
                                }

                                if (typeof this.checkForUpdateElements[element.type + '_' + element.id] !== "undefined" && element.modificationDate > this.checkForUpdateElements[element.type + '_' + element.id].modificationDate && element.type === 'object') {
                                    // update data fields
                                    var elementPanel = Ext.getCmp(element.type + '_' + element.id);

                                    var updateTreeAndPanelTitle = function (element) {
                                        // update element tree
                                        Ext.Array.each(opendxp.elementservice.getElementTreeNames(element.type), function (treeName) {
                                            var tree = opendxp.globalmanager.get(treeName);
                                            if (!tree) {
                                                return true;
                                            }

                                            tree.tree.getRootNode().cascade(function (elementTreeNode) {
                                                if (elementTreeNode.id == element.id) {
                                                    if(elementTreeNode.data.parentId == element.parentId) {
                                                        if (this.defaultLanguage !== user.language) {
                                                            elementTreeNode.set('text', this.translateGuiElement(element));
                                                        } else {
                                                            elementTreeNode.set('text', element.key);
                                                        }
                                                    } else {
                                                        opendxp.elementservice.refreshNodeAllTrees(element.type, elementTreeNode.data.parentId);
                                                        setTimeout(function() {
                                                            opendxp.elementservice.refreshNodeAllTrees(element.type, element.parentId);
                                                        }, 500)
                                                    }
                                                }
                                            }.bind(this));
                                        });

                                        // update panel title
                                        if (opendxp.globalmanager.exists(element.type + '_' + element.id)) {
                                            var elementPanel = opendxp.globalmanager.get(element.type + '_' + element.id);
                                            var translation = element.key;
                                            if (this.defaultLanguage !== user.language) {
                                                translation = this.translateGuiElement(element);
                                            }
                                            if (translation && elementPanel.tab) {
                                                elementPanel.tab.initialConfig.title = translation;
                                                elementPanel.tab.setTitle(translation);
                                            }
                                        }
                                    }.bind(this);

                                    if (elementPanel && opendxp.globalmanager.exists(element.type + '_' + element.id) && !opendxp.globalmanager.get(element.type + '_' + element.id).isDirty()) {
                                        var params = { id: element.id };
                                        params.layoutId = elementPanel.object.data.currentLayoutId;

                                        var elementObject = opendxp.globalmanager.get(element.type + '_' + element.id);

                                        if (typeof elementObject !== "undefined" && elementObject.get('type') === 'folder') {
                                            // Update the modification date so that this folder is not checked again until it actually changes
                                            this.checkForUpdate(element.id, 'object', element.modificationDate ?? element.o_modificationDate);
                                        } else {
                                            Ext.Ajax.request({
                                                url: Routing.generate('opendxp_admin_dataobject_dataobject_get'),
                                                params: params,
                                                ignoreErrors: true,
                                                success: function(response) {
                                                    response = Ext.decode(response.responseText);
                                                    if (opendxp.globalmanager.exists(element.type + '_' + element.id) && !opendxp.globalmanager.get(element.type + '_' + element.id).isDirty() && typeof this.checkForUpdateElements[element.type + '_' + element.id] !== "undefined") {
                                                        try {
                                                            var elementObject = opendxp.globalmanager.get(element.type + '_' + element.id);
                                                        elementObject.edit.dataFields = {};
                                                            var tabPanel = elementObject.edit.getRecursiveLayout(response.layout, false, {}, false, false, elementObject.edit).items;
                                                            var uiState = elementObject.getUiState(opendxp.globalmanager.get(element.type + '_' + element.id).tabbar);
                                                            elementObject.edit.object.data.data = response.data;
                                                            Ext.each(response.data, function(fieldValue, fieldName) {
                                                                if(typeof elementObject.autoSaveDetectorInitData !== "undefined" && typeof elementObject.autoSaveDetectorInitData[fieldName] !== "undefined") {
                                                                    elementObject.autoSaveDetectorInitData[fieldName] = fieldValue;
                                                                }
                                                            });

                                                        elementObject.edit.getLayout().removeAll();
                                                        elementObject.edit.getLayout().add(tabPanel);
                                                        elementObject.setUiState(opendxp.globalmanager.get(element.type + '_' + element.id).tabbar, uiState);

                                                            element.key = response.general.key ?? response.general.o_key;

                                                            elementObject.stopChangeDetector();
                                                            elementObject.setupChangeDetector();
                                                        } catch (e) {
                                                            console.log(e);
                                                        }

                                                        updateTreeAndPanelTitle(element);

                                                        this.checkForUpdate(element.id, 'object', element.modificationDate ?? element.o_modificationDate);
                                                    }
                                                }.bind(this)
                                            });
                                        }


                                    }

                                    updateTreeAndPanelTitle(element);
                                }
                            }.bind(this));

                            refresh.call(this);
                        }.bind(this)
                    });
                } else {
                    refresh.call(this);
                }
            }.bind(this), 3000);
        }).bind(this)();

        Ext.Array.each(opendxp.elementservice.getElementTreeNames('object'), function (treeName) {
            var tree = opendxp.globalmanager.get(treeName);
            if (!tree) {
                return true;
            }
        });

        if (user.admin && user.isAllowed("notifications")) {
            var toolbar = opendxp.globalmanager.get("layout_toolbar");

            if(toolbar.notificationMenu) {
                Ext.Ajax.request({
                    url: "/admin/SylphenDataBridge/importconfig/update-available",
                    ignoreErrors: true,
                    success: function (response) {
                        response = Ext.decode(response.responseText);
                        if (!(response && response.success)) {
                            return;
                        }

                        opendxp.notification.helper.incrementCount();
                        toolbar.notificationMenu.add({
                            itemId: 'data_bridge_notification_update',
                            text: t('pim.PIM config')+': '+t("update_available"),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg",
                            handler: function() {
                                let html = '<div class="changelog x-form-item-label-default"><b>'+t('pim.update-available.installed_version')+': ' + response.currentVersion + '</b>';
                                html += '<br><b style="color: darkgreen;">'+t('pim.update-available.latest_version')+': ' + response.latestVersion + '</b>';
                                html += '<br><a href="'+response.url+'" target="_blank"> ' + t('pim.update-available.changes_since').replace('%s', response.currentVersion) + '</a><hr>';
                                html += response.changes;
                                html += '</div>';

                                var window = Ext.create('Ext.window.MessageBox', {
                                    resizable: true,
                                    maximizable: true,
                                    maxWidth: '100%',
                                    maxHeight: '100%',
                                    closeAction: 'destroy'
                                }).show({
                                    title: t('pim.PIM config') + ': ' + t("update_available"),
                                    msg: html,
                                    buttons: Ext.Msg.OK,
                                    width: '80%',
                                    height: '80%'
                                });

                                setTimeout(function() {
                                    window.setWidth(window.getWidth()); // update layout after loading images (otherwise last parts are not visible because ExtJS initially sizes window without image dimensions
                                }, 5000);
                            }
                        });
                    }
                });
            }
        }

        var pimcoreIcon = Ext.get("opendxp_signet");
        if(pimcoreIcon) {
            Ext.Ajax.request({
                url: "/admin/SylphenDataBridge/importconfig/get-version",
                ignoreErrors: true,
                success: function (response) {
                    response = Ext.decode(response.responseText);
                    if (!(response && response.success)) {
                        return;
                    }
                    pimcoreIcon.dom.setAttribute('data-menu-tooltip', pimcoreIcon.dom.getAttribute('data-menu-tooltip') + ' - Data Bridge: ' + response.version)
                }
            });
        }

        if (typeof opendxp.helpers.copyStringToClipboard === "undefined") {
            opendxp.helpers.copyStringToClipboard = function (str) {
                var selection = document.getSelection(),
                    prevSelection = (selection.rangeCount > 0) ? selection.getRangeAt(0) : false,
                    el;

                // create element and insert string
                el = document.createElement('textarea');
                el.value = str;
                el.setAttribute('readonly', '');
                el.style.position = 'absolute';
                el.style.left = '-9999px';

                // insert element, select all text and copy
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);

                // restore previous selection
                if (prevSelection) {
                    selection.removeAllRanges();
                    selection.addRange(prevSelection);
                }
            };
        }

        if (typeof opendxp.object.tags.wysiwyg.defaultEditorConfig !== 'object') {
            opendxp.object.tags.wysiwyg.defaultEditorConfig = {};
        }
        opendxp.object.tags.wysiwyg.defaultEditorConfig = mergeObject({ versionCheck: false }, opendxp.object.tags.wysiwyg.defaultEditorConfig);

        opendxp.elementservice.deleteElementFromServer = function (r, options, button) {
            if (button == "ok" && r.deletejobs) {
                var successHandler = options["success"];
                var elementType = options.elementType;
                var id = options.id;
                const preDeleteEventName = 'preDelete' + elementType.charAt(0).toUpperCase() + elementType.slice(1);

                let ids = Ext.isString(id) ? id.split(',') : [id];
                if (typeof opendxp.events !== 'undefined') {
                    try {
                        ids.forEach(function (elementId) {
                            const preDeleteEvent = new CustomEvent(opendxp.events[preDeleteEventName], {
                                detail: {
                                    elementId: elementId
                                },
                                cancelable: true
                            });

                            const isAllowed = document.dispatchEvent(preDeleteEvent);
                            if (!isAllowed) {
                                r.deletejobs = r.deletejobs.filter((job) => job[0].params.id != elementId);
                                ids = ids.filter((id) => id != elementId);
                            }
                        });
                    } catch (e) {
                        opendxp.helpers.showPrettyError('asset', t("error"), t("delete_failed"), e.message);
                        return;
                    }
                }

                ids.forEach(function (elementId) {
                    opendxp.helpers.addTreeNodeLoadingIndicator(elementType, elementId);
                });

                var affectedNodes = opendxp.elementservice.getAffectedNodes(elementType, id);
                for (var index = 0; index < affectedNodes.length; index++) {
                    var node = affectedNodes[index];
                    if (node) {
                        var nodeEl = Ext.fly(node.getOwnerTree().getView().getNodeByRecord(node));
                        if (nodeEl) {
                            nodeEl.addCls("opendxp_delete");
                        }
                    }
                }

                if (opendxp.globalmanager.exists(elementType + "_" + id)) {
                    var tabPanel = Ext.getCmp("opendxp_panel_tabs");
                    tabPanel.remove(elementType + "_" + id);
                }

                var deleteProgressBar = new Ext.ProgressBar({
                    text: t('initializing')
                });

                var deleteWindow = new Ext.Window({
                    title: t("delete"),
                    layout: 'fit',
                    width: 200,
                    bodyStyle: "padding: 10px;",
                    closable: false,
                    plain: true,
                    items: [deleteProgressBar],
                    listeners: typeof opendxp.helpers.getProgressWindowListeners === "function" ? opendxp.helpers.getProgressWindowListeners() : {}
                });
                deleteWindow.show();

                var timeout;

                Ext.Ajax.request({
                    url: '/admin/SylphenDataBridge/import/delete-elements',
                    method: 'post',
                    params: {
                        "elementType": elementType,
                        "elementIds": id
                    },
                    timeout: 30*60*1000,
                    success: function (response) {
                        response = Ext.decode(response.responseText);
                        if (!(response && response.success)) {
                            deleteWindow.close();

                            opendxp.helpers.showNotification(t("error"), t("error_deleting_item"), "error", t(response.errors.join("\n\n")));
                            return;
                        }

                        var refreshParentNodes = [];
                        const postDeleteEventName = 'postDelete' + elementType.charAt(0).toUpperCase() + elementType.slice(1);
                        for (var index = 0; index < affectedNodes.length; index++) {
                            var node = affectedNodes[index];
                            try {
                                if (node) {
                                    refreshParentNodes[node.parentNode.id] = node.parentNode.id;
                                }
                            } catch (e) {
                                console.log(e);
                                opendxp.helpers.showNotification(t("error"), t("error_deleting_item"), "error");
                                if (node) {
                                    tree.getStore().load({
                                        node: node.parentNode
                                    });
                                }
                            }
                        }

                        for (var parentNodeId in refreshParentNodes) {
                            opendxp.elementservice.refreshNodeAllTrees(elementType, parentNodeId);
                        }

                        if (typeof opendxp.events !== 'undefined') {
                            ids.forEach(function (elementId) {
                                const postDeleteEvent = new CustomEvent(opendxp.events[postDeleteEventName], {
                                    detail: {
                                        elementId: elementId
                                    }
                                });

                                document.dispatchEvent(postDeleteEvent);
                            });
                        }

                        deleteWindow.close();
                        if (timeout) {
                            clearTimeout(timeout);
                        }

                        if (typeof successHandler == "function") {
                            successHandler();
                        }
                    }
                });

                (function refresh () {
                    timeout = setTimeout(function () {
                        Ext.Ajax.request({
                            url: '/admin/SylphenDataBridge/import/delete-elements-status',
                            method: 'post',
                            params: {
                                "elementType": elementType,
                                "elementIds": id
                            },
                            async: false,
                            success: function (response) {
                                response = Ext.decode(response.responseText);
                                if (!(response && response.success)) {
                                    return;
                                }

                                deleteProgressBar.updateProgress(response.progress, Math.round(response.progress * 100) + "%");
                                if (response.progress < 1) {
                                    refresh();
                                }
                            }
                        });
                    }, 300);
                })();
            }
        };

        Ext.Ajax.request({
            url: "/admin/SylphenDataBridge/import/statistics",
            ignoreErrors: true,
            success: function (response) {
                response = Ext.decode(response.responseText);
                if (!(response && response.success)) {
                    return;
                }

                this.defaultLanguage = response.defaultLanguage;

                if (this.defaultLanguage !== user.language) {
                    Ext.Array.each(['object', 'asset', 'document'], function(elementType) {
                        Ext.Array.each(opendxp.elementservice.getElementTreeNames(elementType), function (treeName) {
                            var tree = opendxp.globalmanager.get(treeName);
                            if (!tree) {
                                return true;
                            }

                            tree.tree.getStore().on('load', function (tree, records) {
                                Ext.each(records, function (record) {
                                    record.set('text', t(record.get('text')));
                                });
                            });

                            tree.tree.getRootNode().cascade(function (elementTreeNode) {
                                elementTreeNode.set('text', t(elementTreeNode.get('text')));
                            });
                        });
                    });
                }
            }.bind(this)
        });

        Ext.Ajax.on("beforerequest", function (connection, config) {
            if (typeof config.ignoreErrors !== "undefined" && config.ignoreErrors) {
                xhrActive--;
            }
        });

        Ext.Ajax.on("beforerequest", function (connection, config) {
            if (typeof config.ignoreErrors !== "undefined" && config.ignoreErrors) {
                xhrActive++;
            }
        });
    },

    getHistoryMenuitem: function() {
        var fileMenu = opendxp.globalmanager.get("layout_toolbar").fileMenu;
        var historyMenuItem = fileMenu.queryById('opendxp_menu_file_element_history');
        if (!historyMenuItem) {
            Ext.each(fileMenu.items.items, function (fileMenuItem) {
                if (fileMenuItem.text === t("element_history")) {
                    historyMenuItem = fileMenuItem;
                    return false;
                }
            });
        }
        return historyMenuItem;
    },

    fetchCompatibleDataports: function(objectId, type, callback) {
        Ext.Ajax.request({
            url: "/admin/SylphenDataBridge/importconfig/can-be-executed",
            params: {
                id: objectId,
                type: type
            },
            ignoreErrors: true,
            success: function (response) {
                callback(response);
            }
        });
    },

    postOpenDocument: function (element) {
        let prefix = this.guiTranslationDetails?.document?.translation_key_prefix ?? '';
        this.fetchCompatibleDataports.call(this, element.id, 'document', function (response) {
            this.postOpenElementCallback(element, 'document', response);
        }.bind(this));

        this.modifyHistory(element.id, 'document', { iconCls: element.data.iconCls });

        if (typeof element.tab !== "undefined" && this.defaultLanguage !== opendxp.globalmanager.get("user").language && (this.guiTranslationDetails?.document?.active ?? true)) {
            var translation = t(prefix + element.data.key);
            if (translation === prefix + element.data.key) {
                translation = t(element.data.key);
            }
            if(translation) {
                element.tab.initialConfig.title = translation;
                element.tab.setTitle(translation);
            }
        }
    },

    postOpenAsset: function (element) {
        let prefix = this.guiTranslationDetails?.asset?.translation_key_prefix ?? '';
        this.fetchCompatibleDataports(element.id, 'asset', function (response) {
            this.postOpenElementCallback(element, 'asset', response);
        }.bind(this));

        if (typeof element.tab !== "undefined" && this.defaultLanguage !== opendxp.globalmanager.get("user").language && (this.guiTranslationDetails?.asset?.active ?? true)) {
            var translation = t(prefix + element.data.filename);
            if (translation === prefix + element.data.filename) {
                translation = t(element.data.filename);
            }
            if (translation) {
                element.tab.initialConfig.title = translation;
                element.tab.setTitle(translation);
            }
        }

        if(typeof Routing === 'object') {
            var generateWebdavUrl = function(element) {
                try {
                    return url = Routing.generate('opendxp_admin_webdav', { path: element.data.path + element.data.filename }, true);
                } catch (e) {
                    try {
                        return url = Routing.generate('opendxp_webdav', { path: element.data.path + element.data.filename }, true);
                    } catch(e) {
                        return url = Routing.getScheme() + '://' + Routing.getHost() + (Routing.getHost().indexOf(':' + Routing.getPort()) > -1 || '' === Routing.getPort() ? '' : ':' + Routing.getPort()) + '/asset/webdav/' + element.data.path + element.data.filename;
                    }
                }
            }
            if (['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv'].indexOf(element.data.mimetype) > -1) {
                element.tab.items.get('asset_toolbar_' + element.id).add('-');
                element.tab.items.get('asset_toolbar_' + element.id).add({
                    text: t("pim.direct_open").replace('%s', 'Excel'),
                    tooltip: t("pim.direct_open.tooltip").replace('%s', 'Excel'),
                    iconCls: "opendxp_icon_xls",
                    scale: "medium",
                    href: 'ms-excel:ofe|u|' + generateWebdavUrl(element),
                    hrefTarget: '_self'
                });
            } else if (['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'].indexOf(element.data.mimetype) > -1) {
                element.tab.items.get('asset_toolbar_' + element.id).add('-');
                element.tab.items.get('asset_toolbar_' + element.id).add({
                    text: t("pim.direct_open").replace('%s', 'Word'),
                    tooltip: t("pim.direct_open.tooltip").replace('%s', 'Word'),
                    iconCls: "opendxp_icon_doc",
                    scale: "medium",
                    href: 'ms-word:ofe|u|' + generateWebdavUrl(element),
                    hrefTarget: '_self'
                });
            } else if (['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint'].indexOf(element.data.mimetype) > -1) {
                element.tab.items.get('asset_toolbar_' + element.id).add('-');
                element.tab.items.get('asset_toolbar_' + element.id).add({
                    text: t("pim.direct_open").replace('%s', 'Powerpoint'),
                    tooltip: t("pim.direct_open.tooltip").replace('%s', 'Powerpoint'),
                    iconCls: "opendxp_icon_ppt",
                    scale: "medium",
                    href: 'ms-powerpoint:ofe|u|' + generateWebdavUrl(element),
                    hrefTarget: '_self'
                });
            }
        }

        this.modifyHistory(element.id, 'asset', { iconCls: element.data.iconCls });
    },

    postOpenObject: function(element)
    {
        let prefix = this.guiTranslationDetails?.object?.translation_key_prefix ?? '';
        if (typeof opendxp.object.tags.wysiwyg.defaultEditorConfig !== 'object') {
            opendxp.object.tags.wysiwyg.defaultEditorConfig = {};
        }
        opendxp.object.tags.wysiwyg.defaultEditorConfig = mergeObject({ versionCheck: false }, opendxp.object.tags.wysiwyg.defaultEditorConfig);

        if(typeof element.tab !== "undefined" && this.defaultLanguage !== opendxp.globalmanager.get("user").language && (this.guiTranslationDetails?.object?.active ?? true)) {
            var translation = t(prefix + (element.data.general.key ?? element.data.general.o_key));
            if (translation === (element.data.general.key ?? element.data.general.o_key)) {
                translation = (element.data.general.key ?? element.data.general.o_key);
            }
            if(translation) {
                element.tab.initialConfig.title = translation;
                element.tab.setTitle(translation);
            }
        }

        this.checkForUpdate(element.id, 'object', element.data.general.modificationDate ?? element.data.general.o_modificationDate);

        this.fetchCompatibleDataports(element.id, 'object', function(response) {
            this.postOpenElementCallback(element, 'object', response);
        }.bind(this));

        opendxp.object.versions.prototype.publishVersion = function (index, grid) {
            var data = grid.getStore().getAt(index).data;
            var versionId = data.id;

            var currentElementPanel = Ext.getCmp("opendxp_panel_tabs").getActiveTab();
            var objectData = currentElementPanel.object.data;
            var restoreData = {
                objectId: objectData.general.id ?? objectData.general.o_id,
                path: objectData.general.fullpath,
                className: objectData.general.className ?? objectData.general.o_className,
            };

            this.showRestoreWindow([restoreData], data.date);
        }.bind(this);

        this.modifyHistory(element.id, 'object', element.data.type === 'folder' ? {iconCls: 'opendxp_icon_folder'} : { icon: element.data.general.icon});
    },

    modifyHistory: function(elementId, elementType, elementData) {
        // post open event gets fired before opendxp.helpers.recordElement
        setTimeout(function() {
            var history = opendxp.helpers.getHistory();
            for (var i = 0; i < history.length; i++) {
                if (history[i].id == elementId && history[i].type == elementType) {
                    history[i] = Object.assign(history[i], elementData);
                }
            }
            var json = JSON.stringify(history);
            localStorage.setItem("opendxp_element_history", json);

            var historyMenuItem = this.getHistoryMenuitem();
            if (historyMenuItem) {
                historyMenuItem.menu.removeAll();

                var history = opendxp.helpers.getHistory();
                var historyMenuItems = [];
                Ext.Array.each(history, function (historyItem) {
                    var path = historyItem.name.split('/').pop();

                    var menuItemData = {
                        text: path || t('home'),
                        handler: function () {
                            opendxp.helpers.openElement(historyItem.id, historyItem.type);
                        }
                    }

                    if (typeof historyItem.icon !== "undefined" && historyItem.icon) {
                        menuItemData.icon = historyItem.icon;
                    } else if (typeof historyItem.iconCls !== "undefined" && historyItem.iconCls) {
                        menuItemData.iconCls = historyItem.iconCls;
                    } else {
                        menuItemData.iconCls = "opendxp_nav_icon_object opendxp_icon_overlay_go";
                    }

                    historyMenuItem.menu.add(menuItemData);
                });
            }
        }.bind(this), 500);
    },

    postOpenElementCallback: function(object, elementType, response) {
        response = Ext.decode(response.responseText);
        if (!(response && response.success)) {
            return;
        }

        var clickListener = function (menuItem, dataport) {
            if (dataport.sourcetype === 'object-wizard') {
                var dataportParameters = Object.assign({}, dataport.parameters);

                // get selected objects from grid
                if(typeof object.search !== "undefined" && typeof object.search.grid !== "undefined") {
                    const selections = object.search.grid.getSelectionModel().getSelection();
                    let selectionData = [];
                    Ext.each(selections, function (selection) {
                        selectionData.push(selection.data);
                    });

                    for (var wizardFieldName in dataport.parameters) {
                        if (typeof dataport.parameters[wizardFieldName].classes !== "undefined" && dataport.parameters[wizardFieldName].classes.indexOf(object.search.classId) > -1) {
                            dataportParameters[wizardFieldName] = selectionData;
                        }
                    }
                }

                opendxp.plugin.Pim.plugin.startDataport(dataport.id, dataportParameters);
            } else {
                opendxp.plugin.Pim.plugin.runDataport(dataport.id, dataport.url);
            }
        }

        if (response.dataports.imports.length > 0) {
            var imports = [];
            Ext.each(response.dataports.imports, function (dataport) {
                var menuItem = {
                    text: dataport.name,
                    icon: dataport.icon,
                    iconCls: "opendxp_icon_overlay_download",
                    margin: "0 0 0 10",
                    listeners: {
                        click: function (menuItem) {
                            clickListener(menuItem, dataport);
                        }
                    }
                };
                imports.push(menuItem);
            });

            var importItem = {
                tooltip: t("import"),
                itemId: "importButton",
                iconCls: "opendxp_icon_data_bridge",
                style: {
                    backgroundPosition: "center"
                },
                scale: "medium",
                menu: imports
            };

            var insertIndex = null;
            object.tab.items.get(elementType+'_toolbar_' + object.id).items.each(function (toolbarItem, index) {
                if (toolbarItem.xtype === 'tbtext' && toolbarItem.text === 'ID ' + object.id) {
                    insertIndex = index;
                    return false;
                }
            });

            if (insertIndex) {
                object.tab.items.get(elementType+'_toolbar_' + object.id).insert(insertIndex, '-');
                object.tab.items.get(elementType+'_toolbar_' + object.id).insert(insertIndex, importItem);
            } else {
                object.tab.items.get(elementType+'_toolbar_' + object.id).add('-');
                object.tab.items.get(elementType+'_toolbar_' + object.id).add(importItem);
            }
        }

        if (response.dataports.exports.length > 0) {
            var exports = [];
            Ext.each(response.dataports.exports, function (dataport) {
                exports.push({
                    text: dataport.name,
                    icon: dataport.icon,
                    margin: "0 0 0 10",
                    iconCls: "opendxp_icon_overlay_upload",
                    listeners: {
                        click: function (menuItem) {
                            clickListener(menuItem, dataport);
                        }
                    }
                });
            });

            var exportItem = {
                tooltip: t("export"),
                itemId: "exportButton",
                iconCls: "opendxp_icon_data_bridge_export",
                scale: "medium",
                menu: exports
            };

            var insertIndex = null;
            object.tab.items.get(elementType + '_toolbar_' + object.id).items.each(function (toolbarItem, index) {
                if (toolbarItem.xtype === 'tbtext' && toolbarItem.text === 'ID ' + object.id) {
                    insertIndex = index;
                    return false;
                }
            });

            if (insertIndex) {
                object.tab.items.get(elementType+'_toolbar_' + object.id).insert(insertIndex, '-');
                object.tab.items.get(elementType+'_toolbar_' + object.id).insert(insertIndex, exportItem);
            } else {
                object.tab.items.get(elementType+'_toolbar_' + object.id).add('-');
                object.tab.items.get(elementType+'_toolbar_' + object.id).add(exportItem);
            }
        }
    },

    prepareObjectTreeContextMenu: function (menu, tree, object) {
        if (tree.tree.getSelectionModel().getSelected().length === 1) {
            this.checkForUpdate(object.id, 'object', Math.floor(Date.now() / 1000));

            this.fetchCompatibleDataports(object.id, object.data.elementType, function (response) {
                response = Ext.decode(response.responseText);
                if (!(response && response.success)) {
                    return;
                }

                var dataports = {
                    imports: [],
                    exports: []
                };
                Ext.each(response.dataports.imports, function (dataport) {
                    var menuItem = {
                        text: dataport.name,
                        icon: dataport.icon,
                        iconCls: "opendxp_icon_overlay_download",
                        handler: function () {
                            opendxp.plugin.Pim.plugin.runDataport(dataport.id, dataport.url);
                        }
                    };
                    dataports.imports.push(menuItem);
                });
                Ext.each(response.dataports.exports, function (dataport) {
                    dataports.exports.push({
                        text: dataport.name,
                        icon: dataport.icon,
                        iconCls: "opendxp_icon_overlay_upload",
                        handler: function() {
                            opendxp.plugin.Pim.plugin.runDataport(dataport.id, dataport.url);
                        }
                    });
                });

                if (dataports.imports.length > 0 || dataports.exports.length > 0) {
                    menu.add('-');
                }

                if (dataports.imports.length > 0) {
                    var importItem = {
                        text: t("import"),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg",
                        menu: dataports.imports
                    };

                    menu.add(importItem);
                }

                if (dataports.exports.length > 0) {
                    var exportItem = {
                        text: t("export"),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/export.svg",
                        menu: dataports.exports
                    };

                    menu.add(exportItem);
                }
            });
        }

        if(object.data.permissions.versions) {
            var selectedObjects = [];

            tree.tree.getSelectionModel().getSelected().each(function (item) {
                selectedObjects.push(item);
            });

            if(selectedObjects.length === 0) {
                selectedObjects = [object];
            }

            selectedObjects = selectedObjects.map(function(selectedObject) {
                return {
                    objectId: selectedObject.data.id ?? selectedObject.data.o_id,
                    path: selectedObject.data.path,
                    className: selectedObject.data.className,
                };
            });

            var restoreVersionMenuItem = {
                text: t('pim.restore_version'),
                iconCls: "opendxp_icon_versioning",
                handler: function() {
                    this.showRestoreWindow(selectedObjects);
                }.bind(this)
            };

            var advancedMenuItem = null;
            Ext.each(menu.items.items, function (contextMenuItem) {
                if (contextMenuItem.text === t('advanced')) {
                    advancedMenuItem = contextMenuItem;
                    return false;
                }
            });

            if(advancedMenuItem === null) {
                advancedMenuItem = menu.add({
                    text: t('advanced'),
                    iconCls: "opendxp_icon_more",
                    hideOnClick: false,
                    menu: [restoreVersionMenuItem]
                });
            } else {
                advancedMenuItem.menu.add(restoreVersionMenuItem);
            }
        }
    },

    showRestoreWindow: function(selectedObjects, date) {
        var selectedObjectsStore = Ext.create('Ext.data.Store', {
            fields: ['id', 'path', { name: 'className', defaultValue: t('folder') }, 'mode'],
            data: selectedObjects
        });

        var restoreGrid = Ext.create('Ext.grid.Panel', {
            margin: '0 0 10 0',
            store: selectedObjectsStore,
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    draggroup: 'element'
                }
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 1
            },
            columns: {
                defaults: {
                    sortable: false
                },
                items: [
                    { text: t("fullpath"), dataIndex: 'path', flex: 1 },
                    { text: t("class"), dataIndex: 'className', width: 100 },
                    {
                        text: t('filter_condition'),
                        dataIndex: 'mode',
                        flex: 1,
                        editor: new Ext.form.ComboBox({
                            forceSelection: false,
                            editable: true,
                            store: Ext.create('Ext.data.Store', {
                                fields: ['value', 'label'],
                                data: [
                                    { value: 'objects', label: t('pim.restore_version.mode.objects') },
                                    { value: 'descendants', label: t('pim.restore_version.mode.descendants') }
                                ]
                            }),
                            displayField: 'label',
                            valueField: 'value',
                            matchFieldWidth: false,
                            value: 'objects'
                        }),
                        renderer: function (value, metaData, record) {
                            if (!value) {
                                if (typeof record.get('className') === "undefined") {
                                    value = 'descendants';
                                } else {
                                    value = 'objects';
                                }
                            }

                            if (['descendants', 'objects'].indexOf(value) > -1) {
                                return t('pim.restore_version.mode.' + value);
                            }
                            return value;
                        }
                    }
                ]
            },
            bodyCssClass: "opendxp_object_tag_objects",
            listeners: {
                afterrender: function () {
                    let dropTargetEl = this.getEl();
                    new Ext.dd.DropZone(dropTargetEl, {
                        ddGroup: 'element',
                        getTargetFromEvent: function (e) {
                            return dropTargetEl.dom;
                        },

                        onNodeOver: function (overHtmlNode, ddSource, e, data) {
                            try {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            } catch (e) {
                                console.log(e);
                                return Ext.dd.DropZone.prototype.dropNotAllowed;
                            }
                        }.bind(this),
                        onNodeDrop: function (target, dd, e, data) {
                            selectedObjectsStore.add(data.records)
                            return true;
                        }.bind(this)
                    });
                }
            }
        });

        if (typeof date === "undefined") {
            date = new Date();
        }

        var datefield = Ext.create('Ext.form.field.Date', {
            maxValue: new Date(),
            value: date
        });
        var timefield = Ext.create('Ext.form.field.Time', {
            value: date,
            format: 'H:i:s'
        });

        var dateTimeField = Ext.create('Ext.form.FieldContainer', {
            layout: 'hbox',
            fieldLabel: t('date'),
            items: [datefield, timefield]
        });

        var restoreForm = Ext.create('Ext.form.Panel', {
            padding: 15,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            items: [
                restoreGrid,
                dateTimeField,
                {
                    xtype: 'tagfield',
                    name: 'fields',
                    fieldLabel: t('pim.restore_version.fields'),
                    forceSelection: false,
                    editable: true,
                    listConfig: {
                        tpl: [
                            '<tpl for=".">',
                            '{[typeof values.group !== "undefined" && (xindex === 1 || parent[xindex - 2].group !== values.group) ? "<div style=\'padding: 5px 10px\'>"+values.group+"</div>" : ""]}',
                            '<div role="option" class="x-boundlist-item">{[typeof values.group !== "undefined" ? "&nbsp;&nbsp;" : ""]}{title}',
                            '{[values.title.toLowerCase() != values.name.toLowerCase() ? " ("+values.name+")":""]}',
                            '</div>',
                            '</tpl>'
                        ]
                    },
                    store: Ext.create('Ext.data.JsonStore', {
                        proxy: {
                            type: 'ajax',
                            url: '/admin/SylphenDataBridge/import/get-restore-fields?objectIds=' + Ext.Array.map(selectedObjects, function (restoreRecord) {
                                return restoreRecord.objectId;
                            }).join(','),
                            fields: ['name', 'title'],
                            reader: {
                                type: 'json',
                                rootProperty: 'fields'
                            }

                        },
                        autoLoad: true
                    }),
                    displayField: 'title',
                    valueField: 'name',
                    filterPickList: true,
                    queryMode: 'local',
                    anyMatch: true,
                    autoEl: {
                        tag: 'div',
                        'data-qtip': 'test'
                    }
                },
                {
                    xtype: 'checkbox',
                    name: 'dry-run',
                    boxLabel: t('pim.manual.startimport.dry-run')
                },
                {
                    xtype: 'hidden',
                    name: 'csrfToken',
                    value: opendxp.settings['csrfToken'],
                }
            ]
        });

        var restoreWindow = Ext.create('Ext.Window', {
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            title: t('pim.restore_version'),
            width: 800,
            height: 300,
            items: restoreForm,
            scrollable: true,
            buttons: [{
                text: t('pim.restore_version.startButton'),
                iconCls: 'opendxp_icon_versioning',
                handler: function () {
                    if (restoreForm.getForm().isValid()) {
                        var value = datefield.getValue();
                        var dateString = Ext.Date.format(value, 'Y-m-d');

                        if (timefield.getValue()) {
                            dateString += " " + Ext.Date.format(timefield.getValue(), "H:i:s");
                        } else {
                            dateString += " 00:00:00";
                        }

                        restoreRecords = selectedObjectsStore.queryBy(function () { return true; }).getRange();
                        var restoreObjects = Ext.Array.map(restoreRecords, function (restoreRecord) {
                            return { id: restoreRecord.get('objectId'), 'mode': restoreRecord.get('mode') };
                        });

                        var fields = restoreForm.getForm().findField('fields').getSubmitValue();
                        if (!fields) {
                            fields = [];
                        }

                        var restoreData = {
                            date: Ext.Date.parseDate(dateString, 'Y-m-d H:i:s').getTime() / 1000,
                            objects: JSON.stringify(restoreObjects),
                            fields: fields.join(','),
                            dryRun: restoreForm.getForm().findField('dry-run').getSubmitValue() ? 1 : 0
                        }

                        Ext.Ajax.request({
                            url: '/admin/SylphenDataBridge/import/restore-elements',
                            method: 'post',
                            params: restoreData,
                            success: function (form, response) {
                                response = Ext.decode(form.responseText);
                                if (response && response.success) {
                                    if(!restoreData.dryRun) {
                                        restoreWindow.close();
                                    }

                                    var refreshTimeout;
                                    var store = Ext.create('Ext.data.JsonStore', {
                                        fields: ['field', 'old', 'new', 'diff', 'fullpath', 'elementId', 'elementType'],
                                        groupField: 'fullpath',
                                        proxy: {
                                            type: 'ajax',
                                            url: '/admin/SylphenDataBridge/import/restore-elements/status?statusKey=' + response.statusKey,
                                            reader: {
                                                type: 'json',
                                                rootProperty: 'changedElements',
                                                totalProperty: 'totalItems',
                                                messageProperty: 'message',
                                                keepRawData: true
                                            }
                                        },
                                        remoteSort: true,
                                        listeners: {
                                            load: function () {
                                                var response = store.getProxy().getReader().rawData;

                                                if (response.doneItems && response.totalItems) {
                                                    summaryWindow.queryById('progressBar').setHtml('<div style="background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);"><span class="progress-bar-fill" style="width:' + ((!response.totalItems) ? 0 : round(response.doneItems / response.totalItems * 100)) + '%;display:block;height:22px;background-color:#47ba60;border-radius:3px;transition:width 500ms ease-in-out;text-align:right;padding:2px 10px;white-space:nowrap">' + (response.finishedIn ? response.doneItems + ' / ' + response.totalItems + ', ' + t('pim.manual.startimport.summary.finished_in') + ' ' + response.finishedIn : '') + '</span></div>');
                                                } else {
                                                    summaryWindow.queryById('progressBar').setHtml('<div style="background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);"><span class="progress-bar-fill" style="width:0;display:block;height:22px;background-color:#47ba60;border-radius:3px;transition:width 500ms ease-in-out;text-align:right;padding:2px 10px;white-space:nowrap">' + t('pim.manual.importForm.success') + ' ...</span></div>');
                                                }

                                                elementType = 'object';
                                                if (!response.finished) {
                                                    if (refreshTimeout) {
                                                        clearTimeout(refreshTimeout);
                                                    }
                                                    refreshTimeout = setTimeout(function () {
                                                        store.load();
                                                    }, 1000);
                                                }

                                                var hasRealError = true;
                                                if (response.errors.length > 0 && response.errors[0].message === "") {
                                                    hasRealError = false;
                                                    response.errors[0].message = t('pim.restore_version.errors.none');
                                                }

                                                if(response.errors.length > 0) {
                                                    errorStore.loadData(response.errors, false);
                                                }
                                                summaryWindow.queryById('errorPanel').setTitle(t('pim.manual.startimport.summary.errors') + ' (' + (hasRealError ? response.errors.length : 0) + ')');
                                            }.bind(this)
                                        }
                                    });
                                    store.load();

                                    var errorStore = Ext.create('Ext.data.Store', {
                                        fields: ['fullpath', 'message', 'elementType'],
                                        data: []
                                    });
                                    var panelItems = [
                                        Ext.create('Ext.grid.Panel', {
                                            itemId: 'errorPanel',
                                            collapsible: true,
                                            collapsed: true,
                                            store: errorStore,
                                            title: t('pim.manual.startimport.summary.errors'),
                                            scrollable: true,
                                            columns: [
                                                {
                                                    text: t('element'),
                                                    dataIndex: 'fullpath',
                                                    flex: 1,
                                                    renderer: function (value, meta, record) {
                                                        if (typeof value === "undefined") {
                                                            return t('unknown');
                                                        }
                                                        return '<span style="cursor:pointer;text-decoration:underline">' + value + '</span>';
                                                    }
                                                },
                                                { text: t('message'), dataIndex: 'message', flex: 1 },
                                            ],
                                            viewConfig: {
                                                loadMask: false,
                                                emptyText: t('loading') + ' ...',
                                                enableTextSelection: true
                                            },
                                            listeners: {
                                                cellclick: function (grid, tdElement, columnIndex, record) {
                                                    var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                                                    if (dataIndex === 'elementPath') {
                                                        opendxp.helpers.openElement(record.get('fullpath'), record.get('elementType'));
                                                    }
                                                },
                                                celldblclick: function (grid, cell, cellIndex, record) {
                                                    var dataIndex = grid.getHeaderCt().getHeaderAtIndex(cellIndex).dataIndex;
                                                    if (dataIndex === 'message') {
                                                        opendxp.helpers.copyStringToClipboard(record.get('message'));
                                                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                                                    }
                                                }
                                            }
                                        }),
                                        Ext.create('Ext.Panel', {
                                            itemId: 'restorePanel',
                                            collapsible: true,
                                            collapsed: false,
                                            title: t('pim.manual.startimport.summary.changes'),
                                            scrollable: true,
                                            layout: {
                                                type: 'fit',
                                                align: 'stretch',
                                                pack: 'start'
                                            },
                                            items: [Ext.create('Ext.grid.Panel', {
                                                itemId: 'restoreLogsPanel',
                                                store: store,
                                                scrollable: true,
                                                columns: [
                                                    { text: t('field'), dataIndex: 'field', width: 100 },
                                                    { text: t('pim.manual.startimport.summary.old'), dataIndex: 'old', flex: 1 },
                                                    { text: t('pim.manual.startimport.summary.new'), dataIndex: 'new', flex: 1 },
                                                    { text: t('pim.manual.startimport.summary.diff'), dataIndex: 'diff', flex: 1 }
                                                ],
                                                features: [Ext.create('Ext.grid.feature.Grouping', {
                                                    groupHeaderTpl: '<i>{name}</i>',
                                                    startCollapsed: true,
                                                    enableGroupingMenu: false
                                                })],
                                                listeners: {
                                                    cellclick: function (grid, tdElement, columnIndex, record) {
                                                        opendxp.helpers.openElement(record.get('elementId'), record.get('elementType'));
                                                    }
                                                },
                                                viewConfig: {
                                                    loadMask: false,
                                                    emptyText: t('loading') + ' ...',
                                                    enableTextSelection: true
                                                },
                                                bbar: opendxp.helpers.grid.buildDefaultPagingToolbar(store, { pageSize: 25 }),
                                            })]
                                        })
                                    ];

                                    var summaryWindow = Ext.create('Ext.Window', {
                                        layout: {
                                            type: 'fit',
                                            align: 'stretch',
                                            pack: 'start'
                                        },
                                        title: t("pim.restore_version"),
                                        padding: 10,
                                        width: '80%',
                                        height: '80%',
                                        maximizable: true,
                                        minimizable: true,
                                        bodyPadding: 0,
                                        listeners: {
                                            minimize: function () {
                                                var win = this;
                                                win.toggleCollapse();
                                                if (!win.getCollapsed()) {
                                                    win.center();
                                                } else {
                                                    win.alignTo(document.body, 'bl-bl');
                                                }
                                            }
                                        },
                                        dockedItems: [
                                            Ext.create('Ext.Panel', {
                                                layout: {
                                                    type: 'hbox',
                                                    align: 'stretch'
                                                },
                                                items: [{
                                                    xtype: 'container',
                                                    itemId: 'progressBar',
                                                    flex: 1
                                                }]
                                            })
                                        ],
                                        items: [
                                            Ext.create('Ext.panel.Panel', {
                                                itemId: 'accordion',
                                                layout: {
                                                    type: 'accordion',
                                                    animate: false
                                                },
                                                items: panelItems
                                            })
                                        ]
                                    });
                                    summaryWindow.show();
                                }
                            }
                        });
                    }
                }.bind(this)
            }]
        });

        restoreWindow.show();
    },

    prepareAssetTreeContextMenu: function (menu, tree, object) {
        this.prepareObjectTreeContextMenu(menu, tree, object);
    },
    prepareDocumentTreeContextMenu: function (menu, tree, object) {
        this.prepareObjectTreeContextMenu(menu, tree, object);
    },

    preSaveObject: function (element) {
        delete this.checkForUpdateElements['object_' + element.id];
    },

    postSaveObject: function (object) {
        this.checkForUpdate(object.id, 'object', object.data.general.modificationDate ?? object.data.general.o_modificationDate);
    },

    checkForUpdate: function (elementId, elementType, modificationDate) {
        this.checkForUpdateElements[elementType + '_' + elementId] = {
            id: elementId,
            type: elementType,
            modificationDate: modificationDate
        };
    },

    prepareClassLayoutContextMenu: function(allowedTypes) {
        for (let layout in allowedTypes) {
            if (allowedTypes[layout] !== undefined && allowedTypes[layout].length > 0) {
                allowedTypes[layout].push('dd_htmlContainer');
                allowedTypes[layout].push('dd_button');
            }
        }
    }
});

opendxp.plugin.Pim.plugin.openDataport = function(id) {
    var importConfigID = 'data-bridge';
    try {
        dataportsPanel = opendxp.globalmanager.get(importConfigID);

        dataportsPanel.id = id;
        dataportsPanel.onTreeNodeClick();
    } catch (e) {
        dataportsPanel = new opendxp.plugin.Pim.ImportConfig(importConfigID);
        opendxp.globalmanager.add(importConfigID, dataportsPanel);

        dataportsPanel.dataStore.on('load', function () {
            dataportsPanel.id = id;
            dataportsPanel.onTreeNodeClick();
        }, this, { single: true });
    }

    var dependencyWindow = Ext.ComponentQuery.query('[itemId=dependencyWindow]');
    if(dependencyWindow) {
        Ext.each(dependencyWindow, function(dependencyWindow) {
            dependencyWindow.close();
        });
    }
}

opendxp.plugin.Pim.plugin.startDataport = function(dataportId, parameters) {
    Ext.Ajax.request({
        url: '/admin/SylphenDataBridge/importconfig/get',
        params: {
            id: dataportId
        },
        success: function (response) {
            response = Ext.decode(response.responseText);

            if(response.success) {
                var dataportData = response.data;

                var manualImport = new opendxp.plugin.Pim.ManualImport({
                    dataportId: dataportId,
                    sourceType: dataportData.sourcetype,
                    importType: "complete",
                    isExport: dataportData.itemClass === "0",
                    isMultiStepWizard: dataportData.itemClass === "0" && dataportData.hasDependentDataport
                });
                manualImport.getStartWindow(parameters).show();
                manualImport.getStartWindow().getComponent('startForm').getForm().findField('importType').setValue('complete');
            }
        }.bind(this)
    });
}

opendxp.plugin.Pim.plugin.runDataport = function (dataportId, url) {
    Ext.Ajax.request({
        url: url,
        success: function (response) {
            response = Ext.decode(response.responseText);
            if (!(response && response.statusKey)) {
                Ext.MessageBox.alert(t('error'), t('error'));
            }

            Ext.Ajax.request({
                url: '/admin/SylphenDataBridge/importconfig/get',
                params: {
                    id: dataportId
                },
                success: function (dataportResponse) {
                    dataportResponse = Ext.decode(dataportResponse.responseText);

                    if (dataportResponse.success) {
                        var dataportData = dataportResponse.data;

                        var manualImport = new opendxp.plugin.Pim.ManualImport({
                            dataportId: dataportId,
                            sourceType: dataportData.sourcetype,
                            importType: "complete",
                            isExport: dataportData.itemClass === "0",
                            isMultiStepWizard: dataportData.itemClass === "0" && dataportData.hasDependentDataport
                        });
                        manualImport.showSummaryWindow(response.statusKey);
                    }
                }.bind(this)
            });
        }.bind(this)
    });
}

opendxp.plugin.Pim.plugin.closeStartWindow = function(dataportId) {
    var startWindow = Ext.ComponentQuery.query('[itemId=startWindow'+dataportId+']');
    if(startWindow) {
        Ext.Array.each(startWindow, function(window) {
            window.close();
        });
    }
}

opendxp.plugin.Pim.plugin.updateMaxParallelProcessesSetting = function(maxProcesses) {
    Ext.Ajax.request({
        url: '/admin/SylphenDataBridge/importconfig/update-max-parallel-processes',
        method: 'post',
        params: {
            maxProcesses: maxProcesses
        },
        success: function (response) {
            response = Ext.decode(response.responseText);
            if (!(response && response.success)) {
                return;
            }

            opendxp.helpers.showNotification(t("success"), t('pim.dataport_parallel_processes.updated'), 'success');
        }.bind(this)
    });
};

document.addEventListener("click", function (e) {
    if (e.target.getAttribute('data-createDataportName')) {
        opendxp.globalmanager.get('data-bridge').addDataport('ok', e.target.getAttribute('data-createDataportName'));
    }
});

new opendxp.plugin.Pim.plugin();
