using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text;
using PrintParade.Bridge.Core;

namespace PrintParade.Bridge.Windows;

[SupportedOSPlatform("windows")]
public sealed partial class WindowsRawPrintSpooler : IPrintSpooler
{
    public Task PrintAsync(ClaimedPrintJob job, CancellationToken cancellationToken = default)
    {
        ArgumentOutOfRangeException.ThrowIfLessThan(job.Quantity, 1);

        return Task.Run(() => Print(job, cancellationToken), cancellationToken);
    }

    private static void Print(ClaimedPrintJob job, CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("Raw printer spooling is available only on Windows.");
        }

        if (!NativeMethods.OpenPrinter(job.Printer, out var printerHandle, IntPtr.Zero))
        {
            throw LastWin32Exception($"Unable to open the Windows printer queue '{job.Printer}'.");
        }

        try
        {
            PrintDocument(printerHandle, job, cancellationToken);
        }
        finally
        {
            NativeMethods.ClosePrinter(printerHandle);
        }
    }

    private static void PrintDocument(IntPtr printerHandle, ClaimedPrintJob job, CancellationToken cancellationToken)
    {
        var documentName = Marshal.StringToHGlobalUni($"Print Parade {job.JobId}");
        var dataType = Marshal.StringToHGlobalUni("RAW");
        var documentInfo = new NativeMethods.DocumentInfo
        {
            DocumentName = documentName,
            DataType = dataType,
        };

        try
        {
            StartDocument(printerHandle, job, cancellationToken, ref documentInfo);
        }
        finally
        {
            Marshal.FreeHGlobal(documentName);
            Marshal.FreeHGlobal(dataType);
        }
    }

    private static void StartDocument(
        IntPtr printerHandle,
        ClaimedPrintJob job,
        CancellationToken cancellationToken,
        ref NativeMethods.DocumentInfo documentInfo)
    {
        if (NativeMethods.StartDocPrinter(printerHandle, 1, ref documentInfo) == 0)
        {
            throw LastWin32Exception("Unable to start the Windows spooler document.");
        }

        try
        {
            if (!NativeMethods.StartPagePrinter(printerHandle))
            {
                throw LastWin32Exception("Unable to start the Windows spooler page.");
            }

            WritePage(printerHandle, job, cancellationToken);
        }
        finally
        {
            if (!NativeMethods.EndDocPrinter(printerHandle))
            {
                throw LastWin32Exception("Unable to end the Windows spooler document.");
            }
        }
    }

    private static void WritePage(IntPtr printerHandle, ClaimedPrintJob job, CancellationToken cancellationToken)
    {
        try
        {
            var payload = Encoding.UTF8.GetBytes(job.Payload);

            for (var copy = 0; copy < job.Quantity; copy++)
            {
                cancellationToken.ThrowIfCancellationRequested();
                WritePayload(printerHandle, payload);
            }
        }
        finally
        {
            if (!NativeMethods.EndPagePrinter(printerHandle))
            {
                throw LastWin32Exception("Unable to end the Windows spooler page.");
            }
        }
    }

    private static void WritePayload(IntPtr printerHandle, byte[] payload)
    {
        if (!NativeMethods.WritePrinter(printerHandle, payload, payload.Length, out var bytesWritten))
        {
            throw LastWin32Exception("Windows rejected the raw printer payload.");
        }

        if (bytesWritten != payload.Length)
        {
            throw new IOException($"Windows accepted only {bytesWritten} of {payload.Length} raw printer bytes.");
        }
    }

    private static Win32Exception LastWin32Exception(string message)
    {
        return new Win32Exception(Marshal.GetLastWin32Error(), message);
    }

    private static partial class NativeMethods
    {
        [LibraryImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, StringMarshalling = StringMarshalling.Utf16)]
        [return: MarshalAs(UnmanagedType.Bool)]
        internal static partial bool OpenPrinter(string printerName, out IntPtr printerHandle, IntPtr defaults);

        [LibraryImport("winspool.drv", EntryPoint = "ClosePrinter", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        internal static partial bool ClosePrinter(IntPtr printerHandle);

        [LibraryImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true)]
        internal static partial uint StartDocPrinter(IntPtr printerHandle, uint level, ref DocumentInfo documentInfo);

        [LibraryImport("winspool.drv", EntryPoint = "EndDocPrinter", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        internal static partial bool EndDocPrinter(IntPtr printerHandle);

        [LibraryImport("winspool.drv", EntryPoint = "StartPagePrinter", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        internal static partial bool StartPagePrinter(IntPtr printerHandle);

        [LibraryImport("winspool.drv", EntryPoint = "EndPagePrinter", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        internal static partial bool EndPagePrinter(IntPtr printerHandle);

        [LibraryImport("winspool.drv", EntryPoint = "WritePrinter", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        internal static partial bool WritePrinter(
            IntPtr printerHandle,
            [In] byte[] buffer,
            int bufferLength,
            out int bytesWritten);

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        internal struct DocumentInfo
        {
            internal IntPtr DocumentName;

            internal IntPtr OutputFile;

            internal IntPtr DataType;
        }
    }
}
