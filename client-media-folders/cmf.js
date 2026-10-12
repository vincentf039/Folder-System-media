/* Client Media Folders: folder bar for every WordPress media modal. */
(function ($, wp, _) {
	'use strict';

	if (!wp || !wp.media || typeof CMF === 'undefined') {
		return;
	}

	var i18n = CMF.i18n;

	/* ------------------------------------------------------------------
	 * Shared state
	 * ---------------------------------------------------------------- */
	var state = _.extend({ folders: CMF.folders || [], current: 'all' }, Backbone.Events);

	function setFolders(list) {
		state.folders = list || [];
		state.trigger('change:folders');
	}

	function setCurrent(id) {
		state.current = id;
		state.trigger('change:current');
	}

	function folderName(id) {
		var f = _.findWhere(state.folders, { id: id });
		return f ? f.name : '';
	}

	function fmt(str) {
		var args = _.toArray(arguments).slice(1);
		return str.replace(/%(\d)\$d|%d/g, function (m, n) {
			return n ? args[parseInt(n, 10) - 1] : args[0];
		});
	}

	function post(action, data) {
		return $.post(CMF.ajaxUrl, _.extend({ action: action, nonce: CMF.nonce }, data)).then(
			function (res) {
				if (!res || !res.success) {
					return $.Deferred().reject((res && res.data && res.data.message) || i18n.error).promise();
				}
				return res.data;
			},
			function () {
				return $.Deferred().reject(i18n.error).promise();
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Uploads go into the folder that is currently selected.
	 * ---------------------------------------------------------------- */
	function pushFolderToUploader(frame) {
		if (wp.Uploader) {
			wp.Uploader.defaults = wp.Uploader.defaults || {};
			wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {};
			wp.Uploader.defaults.multipart_params.cmf_folder = state.current;
		}
		var win = frame && frame.uploader;
		var up = win && win.uploader && win.uploader.uploader;
		if (up && up.settings) {
			up.settings.multipart_params = up.settings.multipart_params || {};
			up.settings.multipart_params.cmf_folder = state.current;
			if (typeof up.setOption === 'function') {
				up.setOption('multipart_params', up.settings.multipart_params);
			}
		}
	}

	// Safety net: also stamp the folder right before every upload.
	if (wp.Uploader && wp.Uploader.prototype) {
		var origInit = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			if (origInit) {
				origInit.apply(this, arguments);
			}
			if (this.uploader && this.uploader.bind) {
				this.uploader.bind('BeforeUpload', function (up) {
					up.settings.multipart_params = up.settings.multipart_params || {};
					up.settings.multipart_params.cmf_folder = state.current;
				});
			}
		};
	}

	/* ------------------------------------------------------------------
	 * Helpers for markup
	 * ---------------------------------------------------------------- */
	function optionsHtml(selected, withAll) {
		var html = '';
		if (withAll) {
			html += '<option value="all"' + (selected === 'all' ? ' selected' : '') + '>' + _.escape(i18n.allFiles) + '</option>';
		}
		html += '<option value="none"' + (selected === 'none' ? ' selected' : '') + '>' + _.escape(i18n.unfiled) + '</option>';
		_.each(state.folders, function (f) {
			html += '<option value="' + _.escape(f.id) + '"' + (selected === f.id ? ' selected' : '') + '>' + _.escape(f.name) + '</option>';
		});
		return html;
	}

	function chipHtml(id, label, current) {
		var active = id === current;
		return '<button type="button" class="cmf-chip' + (active ? ' is-active' : '') + '" data-id="' + _.escape(id) +
			'" role="tab" aria-selected="' + (active ? 'true' : 'false') + '">' + _.escape(label) + '</button>';
	}

	/* ------------------------------------------------------------------
	 * Folder bar view (two rows: folder chips, then actions)
	 * ---------------------------------------------------------------- */
	var FolderBar = wp.media.View.extend({
		className: 'cmf-bar',

		events: {
			'click .cmf-chip': 'onChip',
			'click .cmf-new': 'createFolder',
			'click .cmf-rename': 'renameFolder',
			'click .cmf-delete': 'deleteFolder',
			'click .cmf-move': 'moveSelected'
		},

		initialize: function (options) {
			wp.media.View.prototype.initialize.apply(this, arguments);
			var self = this;

			this.library = options.library;
			this.selection = options.selection;
			this.targetValue = 'none';

			this.listenTo(state, 'change:folders change:current', this.render);
			if (this.selection) {
				this.listenTo(this.selection, 'add remove reset', this.toggleMove);
			}

			if (this.library && this.library.props) {
				// cache:false makes every folder switch hit the server (no stale lists).
				this.library.props.set('cache', false, { silent: true });
				if (state.current !== 'all') {
					this.library.props.set('cmf_folder', state.current);
				}
			}

			if (this.controller) {
				this.controller.on('open uploader:ready', function () {
					pushFolderToUploader(self.controller);
				});
			}
			pushFolderToUploader(this.controller);
		},

		render: function () {
			var cur = state.current;
			var real = cur !== 'all' && cur !== 'none';
			var keep = this.$('.cmf-target').val();
			if (keep) {
				this.targetValue = keep;
			}

			var chips = chipHtml('all', i18n.allFiles, cur) + chipHtml('none', i18n.unfiled, cur);
			_.each(state.folders, function (f) {
				chips += chipHtml(f.id, f.name, cur);
			});

			this.$el.html(
				'<div class="cmf-row cmf-chips" role="tablist" aria-label="' + _.escape(i18n.folders) + '">' +
					'<span class="cmf-title">' + _.escape(i18n.folders) + '</span>' + chips +
					'<button type="button" class="button cmf-new">' + _.escape(i18n.newFolder) + '</button>' +
				'</div>' +
				'<div class="cmf-row cmf-actions">' +
					'<button type="button" class="button cmf-rename"' + (real ? '' : ' disabled') + '>' + _.escape(i18n.rename) + '</button>' +
					'<button type="button" class="button cmf-delete"' + (real ? '' : ' disabled') + '>' + _.escape(i18n.delete) + '</button>' +
					'<span class="cmf-sep"></span>' +
					'<label class="cmf-label">' + _.escape(i18n.moveTo) +
					' <select class="cmf-target">' + optionsHtml(this.targetValue, false) + '</select></label>' +
					'<button type="button" class="button button-primary cmf-move" disabled title="' + _.escape(i18n.selectHint) + '">' + _.escape(i18n.move) + '</button>' +
					'<span class="cmf-msg" aria-live="polite"></span>' +
				'</div>'
			);

			this.toggleMove();
			return this;
		},

		toggleMove: function () {
			var has = this.selection && this.selection.length > 0;
			this.$('.cmf-move').prop('disabled', !has);
		},

		notify: function (msg, isError) {
			var $m = this.$('.cmf-msg').text(msg).toggleClass('is-error', !!isError);
			clearTimeout(this._t);
			this._t = setTimeout(function () { $m.text(''); }, 4000);
		},

		fail: function (msg) {
			this.notify(msg || i18n.error, true);
		},

		setFilter: function (id) {
			setCurrent(id);
			if (this.library && this.library.props) {
				this.library.props.set('cmf_folder', id);
			}
			pushFolderToUploader(this.controller);
		},

		onChip: function (e) {
			this.setFilter($(e.currentTarget).attr('data-id'));
		},

		createFolder: function () {
			var self = this;
			var name = window.prompt(i18n.newPrompt);
			if (name === null) { return; }
			post('cmf_create', { name: name }).done(function (d) {
				setFolders(d.folders);
				self.setFilter(d.id);
			}).fail(_.bind(this.fail, this));
		},

		renameFolder: function () {
			var id = state.current;
			var name = window.prompt(i18n.renamePrompt, folderName(id));
			if (name === null) { return; }
			post('cmf_rename', { id: id, name: name }).done(function (d) {
				setFolders(d.folders);
			}).fail(_.bind(this.fail, this));
		},

		deleteFolder: function () {
			var self = this;
			var id = state.current;
			if (!window.confirm(i18n.deleteConfirm)) { return; }
			post('cmf_delete', { id: id }).done(function (d) {
				if (self.library) {
					self.library.each(function (m) {
						if (m.get('cmfFolder') === id) { m.set('cmfFolder', ''); }
					});
				}
				setFolders(d.folders);
				self.setFilter('all');
			}).fail(_.bind(this.fail, this));
		},

		moveSelected: function () {
			var self = this;
			var target = this.$('.cmf-target').val();
			var models = this.selection ? this.selection.models.slice() : [];
			var ids = _.pluck(models, 'id');
			if (!ids.length) { return; }

			this.targetValue = target;
			post('cmf_move', { ids: ids, folder: target }).done(function (d) {
				var movedModels = _.filter(models, function (m) {
					return _.contains(d.moved, m.id);
				});
				_.each(movedModels, function (m) {
					m.set('cmfFolder', target === 'none' ? '' : target);
				});
				// Viewing a specific folder and moved elsewhere: drop them from this view.
				if (state.current !== 'all' && state.current !== target && self.library) {
					self.library.remove(movedModels);
					self.selection.remove(movedModels);
				}
				if (movedModels.length === models.length) {
					self.notify(fmt(i18n.moved, movedModels.length));
				} else {
					self.notify(fmt(i18n.movedPartial, movedModels.length, models.length), movedModels.length === 0);
				}
			}).fail(_.bind(this.fail, this));
		}
	});

	/* ------------------------------------------------------------------
	 * Inject the bar into every media browser (modal, grid, editors).
	 * ---------------------------------------------------------------- */
	var Orig = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = Orig.extend({
		createToolbar: function () {
			Orig.prototype.createToolbar.apply(this, arguments);

			var opts = this.options || {};
			var st = this.controller && this.controller.state ? this.controller.state() : null;
			var library = this.collection || opts.collection || (st && st.get('library'));
			var selection = opts.selection || (st && st.get('selection'));

			this.$el.addClass('cmf-has-bar');
			this.views.add(new FolderBar({
				controller: this.controller,
				library: library,
				selection: selection
			}).render(), { at: 0 });
		}
	});

	/* ------------------------------------------------------------------
	 * Per-file "Folder" control in the attachment details panel.
	 * Works in the modal sidebar and the Media Library grid details modal.
	 * ---------------------------------------------------------------- */
	function renderDetailsFolder(view) {
		var model = view.model;
		if (!model) { return; }

		view.$('.cmf-details').remove();

		// Only the file's author can move it.
		if (String(model.get('author')) !== String(CMF.userId)) { return; }

		var $host = view.$('.attachment-info').first();
		if (!$host.length) { $host = view.$el; }

		var $box = $(
			'<div class="cmf-details">' +
				'<label><span class="cmf-details-label"></span>' +
				'<select class="cmf-details-select"></select></label>' +
				'<span class="cmf-details-msg" aria-live="polite"></span>' +
			'</div>'
		);
		$box.find('.cmf-details-label').text(i18n.folder);
		$box.find('select').html(optionsHtml(model.get('cmfFolder') || 'none', false));

		$box.find('select').on('change', function () {
			var target = $(this).val();
			var $msg = $box.find('.cmf-details-msg').text('…');
			post('cmf_move', { ids: [model.id], folder: target }).done(function (d) {
				if (_.contains(d.moved, model.id)) {
					model.set('cmfFolder', target === 'none' ? '' : target);
				} else {
					$msg.text(i18n.error).addClass('is-error');
				}
			}).fail(function (msg) {
				$msg.text(msg || i18n.error).addClass('is-error');
			});
		});

		$host.append($box);
	}

	var Details = wp.media.view.Attachment && wp.media.view.Attachment.Details;
	if (Details && Details.prototype) {
		var origDetailsRender = Details.prototype.render;
		Details.prototype.render = function () {
			var out = origDetailsRender.apply(this, arguments);
			try { renderDetailsFolder(this); } catch (err) { /* never break the media modal */ }
			return out;
		};
	}

})(jQuery, window.wp, window._);
