var e107 = e107 || {'settings': {}, 'behaviors': {}};

(function ($)
{

	/**
	 * Attaches the batch behavior to progress bars.
	 */
	e107.behaviors.batch = {
		attach: function (context, settings)
		{
			$('#progress', context).once('batch').each(function ()
			{
				var $holder = $(this);

				// Success: redirect to the summary.
				var updateCallback = function (progress, status, pb)
				{
					if(progress == 100)
					{
						pb.stopMonitoring();
						window.location = settings.batch.uri + '&op=finished';
					}
				};

				var errorCallback = function (pb)
				{
					$holder.prepend($('<p class="error"></p>').html(settings.batch.errorMessage));
					$('#wait').hide();
				};

				var progress = new e107.progressBar('updateprogress', updateCallback, 'POST', errorCallback);

				progress.setProgress(-1, settings.batch.initMessage);
				$holder.append(progress.element);
				progress.startMonitoring(settings.batch.uri + '&op=do', 10);
			});
		}
	};

})(jQuery);

(function ($)
{

	/**
	 * A progressbar object. Initialized with the given id. Must be inserted into
	 * the DOM afterwards through progressBar.element.
	 *
	 * method is the function which will perform the HTTP request to get the
	 * progress bar state. Either "GET" or "POST".
	 *
	 * e.g. pb = new progressBar('myProgressBar');
	 *      some_element.appendChild(pb.element);
	 */
	e107.progressBar = function (id, updateCallback, method, errorCallback)
	{
		var pb = this;
		this.id = id;
		this.method = method || 'GET';
		this.updateCallback = updateCallback;
		this.errorCallback = errorCallback;

		// The WAI-ARIA setting aria-live="polite" will announce changes after users
		// have completed their current activity and not interrupt the screen reader.
		this.element = $('<div class="progress-wrapper" aria-live="polite"></div>');
		this.element.html('<div id ="' + id + '" class="progress progress-striped active">' +
			'<div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
			'<div class="percentage sr-only"></div>' +
			'</div></div>' +
			'</div><div class="percentage pull-right"></div>' +
			'<div class="message">&nbsp;</div>');
	};

	/**
	 * Set the percentage and status message for the progressbar.
	 */
	e107.progressBar.prototype.setProgress = function (percentage, message)
	{
		if(percentage >= 0 && percentage <= 100)
		{
			$('div.progress-bar', this.element).css('width', percentage + '%');
			$('div.progress-bar', this.element).attr('aria-valuenow', percentage);
			$('div.percentage', this.element).html(percentage + '%');
		}

		$('div.message', this.element).html(message);

		if(this.updateCallback)
		{
			this.updateCallback(percentage, message, this);
		}
	};

	/**
	 * Start monitoring progress via Ajax.
	 */
	e107.progressBar.prototype.startMonitoring = function (uri, delay)
	{
		this.delay = delay;
		this.uri = uri;
		this.sendPing();
	};

	/**
	 * Stop monitoring progress via Ajax.
	 */
	e107.progressBar.prototype.stopMonitoring = function ()
	{
		clearTimeout(this.timer);
		// This allows monitoring to be stopped from within the callback.
		this.uri = null;
	};

	/**
	 * Request progress data from server.
	 */
	e107.progressBar.prototype.sendPing = function ()
	{
		if(this.timer)
		{
			clearTimeout(this.timer);
		}

		if(this.uri)
		{
			var pb = this;
			// When doing a post request, you need non-null data. Otherwise a
			// HTTP 411 or HTTP 406 (with Apache mod_security) error may result.
			$.ajax({
				type: this.method,
				url: this.uri,
				data: '',
				dataType: 'json',
				success: function (progress)
				{
					// Display errors.
					if(progress.status == 0)
					{
						pb.displayError(progress.data);
						return;
					}
					// Update display.
					pb.setProgress(progress.percentage, progress.message);
					// Schedule next timer.
					pb.timer = setTimeout(function ()
					{
						pb.sendPing();
					}, pb.delay);
				},
				error: function (xmlhttp)
				{
					pb.displayError(e107.ajaxError(xmlhttp, pb.uri));
				}
			});
		}
	};

	/**
	 * Display errors on the page.
	 */
	e107.progressBar.prototype.displayError = function (string)
	{
		var error = $('<div class="alert alert-block alert-error"><a class="close" data-dismiss="alert" href="#">&times;</a><h4>Error message</h4></div>').append(string);
		$(this.element).before(error).hide();

		if(this.errorCallback)
		{
			this.errorCallback(this);
		}
	};

})(jQuery);
